<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Workspaces\WorkspaceRuntimeArtifactRemovalOutcome;
use App\Enums\Workspaces\WorkspaceRuntimeContainerApplyOutcome;
use App\Models\Node;
use App\Services\Apps\AppDevelopmentPackagesMount;
use App\Services\Runtime\DockerCommandBuilder;
use RuntimeException;
use Throwable;

final readonly class WorkspaceRuntimeContainerManager
{
    public function __construct(
        private RemoteShell $remoteShell,
        private DockerCommandBuilder $commands,
    ) {}

    public function apply(Node $node, WorkspaceRuntimeContainer $container): WorkspaceRuntimeContainerApplyOutcome
    {
        $this->ensureNetwork($node, $container);

        // Container inspect runs before the image preflight so we know whether
        // a container already exists on the node. The image preflight then
        // uses that knowledge to throw with the correct hadExistingContainer
        // signal when the probe itself fails for an unknown reason.
        $inspection = $this->inspect($node, $container);
        $hadExistingContainer = $inspection !== null;

        // The selected FrankenPHP image must be present on the node for every
        // apply outcome — including the matching-running ("Unchanged") and
        // matching-stopped ("Started") paths. If the image was pruned out
        // from under a still-existing container, doctor/workspace surfaces
        // must report this as `workspace.php_version_unavailable` rather than
        // treating the runtime as healthy or collapsing it into runtime-
        // artifact drift.
        $this->ensureImageAvailable($node, $container, $hadExistingContainer);

        try {
            if ($inspection === null) {
                $this->createContainer($node, $container);

                return WorkspaceRuntimeContainerApplyOutcome::Created;
            }

            if (! $this->matchesSpec($inspection, $container)) {
                $this->runRequired(
                    $node,
                    $this->commands->containerRemove($container->name()),
                    "remove drifted {$container->name()} container",
                );
                $this->createContainer($node, $container);

                return WorkspaceRuntimeContainerApplyOutcome::Recreated;
            }

            if (! $this->isRunning($inspection)) {
                $this->runRequired(
                    $node,
                    $this->commands->containerStart($container->name()),
                    "start {$container->name()} container",
                );

                return WorkspaceRuntimeContainerApplyOutcome::Started;
            }

            return WorkspaceRuntimeContainerApplyOutcome::Unchanged;
        } catch (WorkspaceRuntimeImageUnavailableException|WorkspaceRuntimeContainerApplyException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WorkspaceRuntimeContainerApplyException(
                hadExistingContainer: $hadExistingContainer,
                message: $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function ensureImageAvailable(Node $node, WorkspaceRuntimeContainer $container, bool $hadExistingContainer): void
    {
        $image = $container->image();
        $result = $this->run($node, 'docker image inspect '.escapeshellarg($image));

        if ($result->successful()) {
            return;
        }

        if ($this->isDockerNoSuchImage($result)) {
            throw new WorkspaceRuntimeImageUnavailableException(
                image: $image,
                phpVersion: $this->phpVersionFromImage($image),
                message: "FrankenPHP runtime image '{$image}' is not available on node '{$node->name}'.",
            );
        }

        $detail = trim($result->errorOutput().' '.$result->stdout);
        $detail = $detail !== '' ? $detail : 'unknown docker image inspect failure';

        throw new WorkspaceRuntimeContainerApplyException(
            hadExistingContainer: $hadExistingContainer,
            message: "Failed to verify FrankenPHP runtime image '{$image}' on node '{$node->name}': {$detail}",
        );
    }

    private function isDockerNoSuchImage(RemoteShellResult $result): bool
    {
        $message = $result->stderr.' '.$result->stdout;

        return preg_match('/No such image/i', $message) === 1;
    }

    private function phpVersionFromImage(string $image): string
    {
        if (preg_match('/php(?<version>\d+\.\d+)/', $image, $matches) === 1) {
            return $matches['version'];
        }

        return '';
    }

    /**
     * Tri-state container removal. Returns:
     * - AlreadyAbsent only when Docker confirms the container does not exist
     *   on the node;
     * - Removed when the container existed and was removed;
     * - FailedRemaining when the container existed but could not be removed,
     *   or when the existence probe failed for an unknown reason. Unknown
     *   probe failures must NOT be reported as clean absence because the
     *   artifact may still be on the node.
     */
    public function remove(Node $node, string $appSlug, string $workspaceSlug): WorkspaceRuntimeArtifactRemovalOutcome
    {
        $name = $this->containerName($appSlug, $workspaceSlug);

        $inspect = $this->run($node, $this->commands->containerInspect($name));

        if ($inspect->successful() && trim($inspect->stdout) !== '') {
            $result = $this->run($node, $this->commands->containerRemove($name));

            return $result->successful()
                ? WorkspaceRuntimeArtifactRemovalOutcome::Removed
                : WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        if ($this->isDockerNoSuchObject($inspect)) {
            return WorkspaceRuntimeArtifactRemovalOutcome::AlreadyAbsent;
        }

        return WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
    }

    /**
     * Tri-state managed runtime config file removal. The php.ini snippet
     * mounted into the FrankenPHP workspace container lives at
     * `/etc/orbit/workspaces/<app>-<workspace>.ini` on the node.
     */
    public function removeRuntimeConfigFile(Node $node, string $appSlug, string $workspaceSlug): WorkspaceRuntimeArtifactRemovalOutcome
    {
        $path = $this->runtimeConfigPath($appSlug, $workspaceSlug);

        $existence = $this->probeRuntimeConfigExistence($node, $path);

        if ($existence === 'absent') {
            return WorkspaceRuntimeArtifactRemovalOutcome::AlreadyAbsent;
        }

        if ($existence !== 'present') {
            return WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        $remove = $this->run($node, 'sudo rm -f '.escapeshellarg($path));

        if (! $remove->successful()) {
            return WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        return $this->probeRuntimeConfigExistence($node, $path) === 'absent'
            ? WorkspaceRuntimeArtifactRemovalOutcome::Removed
            : WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
    }

    private function probeRuntimeConfigExistence(Node $node, string $path): string
    {
        $script = sprintf(
            <<<'SH'
err="$(sudo test -e %1$s 2>&1)"
ec=$?
if [ "$ec" = "0" ]; then
    printf 'orbit-container-config-probe:present\n'
elif [ "$ec" = "1" ] && [ -z "$err" ]; then
    printf 'orbit-container-config-probe:absent\n'
else
    printf 'orbit-container-config-probe:error\n'
fi
SH,
            escapeshellarg($path),
        );

        $result = $this->run($node, $script);

        if (! $result->successful()) {
            return 'error';
        }

        if (str_contains($result->stdout, 'orbit-container-config-probe:present')) {
            return 'present';
        }

        if (str_contains($result->stdout, 'orbit-container-config-probe:absent')) {
            return 'absent';
        }

        return 'error';
    }

    private function isDockerNoSuchObject(RemoteShellResult $result): bool
    {
        $message = $result->stderr.' '.$result->stdout;

        return preg_match('/No such (object|container)/i', $message) === 1;
    }

    public function writeRuntimeConfigFile(Node $node, WorkspaceRuntimeContainer $container): void
    {
        $this->runRequired(
            $node,
            $this->renderRuntimeConfigWriteScript($container),
            "write managed runtime config for {$container->appSlug()}/{$container->workspaceSlug()}",
        );
    }

    public function runtimeConfigPath(string $appSlug, string $workspaceSlug): string
    {
        return "/etc/orbit/workspaces/{$appSlug}-{$workspaceSlug}.ini";
    }

    public function containerName(string $appSlug, string $workspaceSlug): string
    {
        return "orbit-ws-{$appSlug}-{$workspaceSlug}";
    }

    private function ensureNetwork(Node $node, WorkspaceRuntimeContainer $container): void
    {
        $result = $this->run($node, $this->commands->networkInspect($container->network()));

        if ($result->successful()) {
            return;
        }

        $this->runRequired(
            $node,
            $this->commands->networkCreate($container->network()),
            "create {$container->network()} Docker network",
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspect(Node $node, WorkspaceRuntimeContainer $container): ?array
    {
        $result = $this->run($node, $this->commands->containerInspect($container->name()));

        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->stdout);

        if ($output === '') {
            return null;
        }

        $inspection = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($inspection)) {
            throw new RuntimeException("Docker returned an invalid inspect payload for {$container->name()} on {$node->name}.");
        }

        return $inspection;
    }

    private function createContainer(Node $node, WorkspaceRuntimeContainer $container): void
    {
        $this->runRequired(
            $node,
            $this->renderInstallAndRunScript($container),
            "create {$container->name()} container",
        );
    }

    private function renderInstallAndRunScript(WorkspaceRuntimeContainer $container): string
    {
        return $this->renderRuntimeConfigWriteScript($container).PHP_EOL.$this->commands->runDetached($container);
    }

    private function renderRuntimeConfigWriteScript(WorkspaceRuntimeContainer $container): string
    {
        $phpIniHostPath = $this->findPhpIniMountSource($container);
        $phpIniDirectory = dirname($phpIniHostPath);
        $phpIniContent = $container->phpIniContent();
        $packagesMountScript = $this->renderPackagesMountDirectoryScript($container);
        $configuredMountScript = $this->renderConfiguredMountDirectoryScript($container);

        return sprintf(
            <<<'SH'
set -e
sudo install -d -m 0755 %s
printf %%s %s | base64 -d | sudo tee %s >/dev/null
%s
%s
SH,
            escapeshellarg($phpIniDirectory),
            escapeshellarg(base64_encode($phpIniContent)),
            escapeshellarg($phpIniHostPath),
            $packagesMountScript,
            $configuredMountScript,
        );
    }

    private function renderPackagesMountDirectoryScript(WorkspaceRuntimeContainer $container): string
    {
        $sources = [];

        foreach ($container->mounts() as $mount) {
            if ($mount['target'] !== AppDevelopmentPackagesMount::Target) {
                continue;
            }

            $sourceUser = AppDevelopmentPackagesMount::userForSafeSource($mount['source']);

            if ($sourceUser === null) {
                throw new RuntimeException("Workspace runtime container {$container->name()} has an unsafe packages mount source.");
            }

            $sources[$mount['source']] = $sourceUser;
        }

        if ($sources === []) {
            return '';
        }

        $lines = [];

        foreach ($sources as $source => $sourceUser) {
            $lines[] = sprintf(
                'sudo install -d -m 0775 -o %s -g %s %s',
                escapeshellarg($sourceUser),
                escapeshellarg($sourceUser),
                escapeshellarg($source),
            );
        }

        return implode("\n", $lines);
    }

    private function renderConfiguredMountDirectoryScript(WorkspaceRuntimeContainer $container): string
    {
        $builtInSources = $this->builtInMountSources($container);
        $sources = [];

        foreach ($container->mounts() as $mount) {
            if ($this->isBuiltInRuntimeMountTarget($mount['target'])) {
                continue;
            }

            if (in_array($mount['source'], $builtInSources, true)) {
                continue;
            }

            $sourceUser = $this->userForSafeConfiguredMountSource($mount['source']);

            if ($sourceUser === null) {
                throw new RuntimeException("Workspace runtime container {$container->name()} has an unsafe configured runtime mount source.");
            }

            $sources[$mount['source']] = $sourceUser;
        }

        if ($sources === []) {
            return '';
        }

        $lines = [];

        foreach ($sources as $source => $sourceUser) {
            $lines[] = sprintf(
                'sudo install -d -m 0775 -o %s -g %s %s',
                escapeshellarg($sourceUser),
                escapeshellarg($sourceUser),
                escapeshellarg($source),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function builtInMountSources(WorkspaceRuntimeContainer $container): array
    {
        $sources = [];

        foreach ($container->mounts() as $mount) {
            if ($this->isBuiltInRuntimeMountTarget($mount['target'])) {
                $sources[] = $mount['source'];
            }
        }

        return array_values(array_unique($sources));
    }

    private function isBuiltInRuntimeMountTarget(string $target): bool
    {
        return in_array($target, [
            WorkspaceRuntimeContainer::SourceTarget,
            WorkspaceRuntimeContainer::PhpIniMountTarget,
            AppDevelopmentPackagesMount::Target,
        ], true);
    }

    private function userForSafeConfiguredMountSource(string $source): ?string
    {
        if (preg_match('#^/home/(?<user>[A-Za-z0-9._-]+)/(?<path>.+)$#', $source, $matches) !== 1) {
            return null;
        }

        $path = $matches['path'];

        foreach (['.aws', '.config', '.gnupg', '.ssh'] as $sensitiveDirectory) {
            if ($path === $sensitiveDirectory || str_starts_with($path, "{$sensitiveDirectory}/")) {
                return null;
            }
        }

        if (in_array($path, ['.netrc', '.npmrc', '.composer/auth.json'], true)) {
            return null;
        }

        if (str_starts_with($path, '.composer/auth.json/')) {
            return null;
        }

        return $matches['user'];
    }

    private function findPhpIniMountSource(WorkspaceRuntimeContainer $container): string
    {
        foreach ($container->mounts() as $mount) {
            if ($mount['target'] === WorkspaceRuntimeContainer::PhpIniMountTarget) {
                return $mount['source'];
            }
        }

        throw new RuntimeException("Workspace runtime container {$container->name()} is missing its php.ini mount.");
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function matchesSpec(array $inspection, WorkspaceRuntimeContainer $container): bool
    {
        $labels = $inspection['Config']['Labels'] ?? [];

        if (! is_array($labels)) {
            return false;
        }

        return ($labels[WorkspaceRuntimeContainer::SpecHashLabel] ?? null) === $container->specHash();
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function isRunning(array $inspection): bool
    {
        return ($inspection['State']['Running'] ?? false) === true;
    }

    private function run(Node $node, string $script): RemoteShellResult
    {
        return $this->remoteShell->run($node, $script);
    }

    private function runRequired(Node $node, string $script, string $step): void
    {
        $result = $this->run($node, $script);

        if ($result->successful()) {
            return;
        }

        $output = trim($result->errorOutput().' '.$result->stdout);
        $message = $output !== '' ? $output : 'unknown error';

        throw new RuntimeException("Failed to {$step} on {$node->name}: {$message}");
    }
}
