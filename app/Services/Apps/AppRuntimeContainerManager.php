<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeArtifactRemovalOutcome;
use App\Enums\Apps\AppRuntimeContainerApplyOutcome;
use App\Models\Node;
use App\Services\Runtime\DockerCommandBuilder;
use RuntimeException;
use Throwable;

final readonly class AppRuntimeContainerManager
{
    public function __construct(
        private RemoteShell $remoteShell,
        private DockerCommandBuilder $commands,
    ) {}

    public function apply(Node $node, AppRuntimeContainer $container): AppRuntimeContainerApplyOutcome
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
        // from under a still-existing container, doctor/app surfaces must
        // report this as `app.php_version_unavailable` rather than treating
        // the runtime as healthy or collapsing it into runtime-artifact drift.
        //
        // Unknown Docker daemon/permission failures during the image probe
        // are NOT misreported as missing-image; they throw a generic
        // AppRuntimeContainerApplyException so callers surface a
        // runtime_container_* drift instead of `app.php_version_unavailable`.
        $this->ensureImageAvailable($node, $container, $hadExistingContainer);

        try {
            if ($inspection === null) {
                $container = $this->withResolvedRuntimeUser($node, $container, hadExistingContainer: false);
                $this->createContainer($node, $container);

                return AppRuntimeContainerApplyOutcome::Created;
            }

            if (! $this->matchesSpec($inspection, $container)) {
                $container = $this->withResolvedRuntimeUser($node, $container, hadExistingContainer: true);
                $this->runRequired(
                    $node,
                    $this->commands->containerRemove($container->name()),
                    "remove drifted {$container->name()} container",
                );
                $this->createContainer($node, $container);

                return AppRuntimeContainerApplyOutcome::Recreated;
            }

            if (! $this->isRunning($inspection)) {
                $this->runRequired(
                    $node,
                    $this->commands->containerStart($container->name()),
                    "start {$container->name()} container",
                );

                return AppRuntimeContainerApplyOutcome::Started;
            }

            return AppRuntimeContainerApplyOutcome::Unchanged;
        } catch (AppRuntimeImageUnavailableException|AppRuntimeContainerApplyException|AppRuntimeUserUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AppRuntimeContainerApplyException(
                hadExistingContainer: $hadExistingContainer,
                message: $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * Preflight the selected FrankenPHP image on the node before
     * creating/recreating/starting/returning the container. Distinguishes
     * definite "image not on node" from unknown probe failure:
     *
     * - `docker image inspect` exits 0 and emits the image JSON → image present.
     * - Exits non-zero with stderr containing "No such image" → throw
     *   {@see AppRuntimeImageUnavailableException}, mapped to
     *   `app.php_version_unavailable` by callers.
     * - Other failures (daemon down, permission denied, SSH error) throw
     *   {@see AppRuntimeContainerApplyException}; this surfaces a
     *   runtime_container_missing/mismatch drift instead of misreporting the
     *   PHP version as unavailable. Unknown failures must NEVER reach the
     *   Unchanged/Started/Recreated/Created branches because that would
     *   imply the runtime image was verified when it was not.
     */
    private function ensureImageAvailable(Node $node, AppRuntimeContainer $container, bool $hadExistingContainer): void
    {
        $image = $container->image();
        $result = $this->run($node, 'docker image inspect '.escapeshellarg($image));

        // Exit 0 → docker reports the image present (regardless of whether
        // stdout carries the inspect JSON). Treat the success exit code as
        // authoritative; a downstream docker run will still fail loudly if
        // anything is wrong.
        if ($result->successful()) {
            return;
        }

        if ($this->isDockerNoSuchImage($result)) {
            throw new AppRuntimeImageUnavailableException(
                image: $image,
                phpVersion: $this->phpVersionFromImage($image),
                message: "FrankenPHP runtime image '{$image}' is not available on node '{$node->name}'.",
            );
        }

        $detail = trim($result->errorOutput().' '.$result->stdout);
        $detail = $detail !== '' ? $detail : 'unknown docker image inspect failure';

        throw new AppRuntimeContainerApplyException(
            hadExistingContainer: $hadExistingContainer,
            message: "Failed to verify FrankenPHP runtime image '{$image}' on node '{$node->name}': {$detail}",
        );
    }

    private function withResolvedRuntimeUser(Node $node, AppRuntimeContainer $container, bool $hadExistingContainer): AppRuntimeContainer
    {
        $runtimeUser = $container->runtimeUser();

        if ($runtimeUser === null) {
            return $container;
        }

        $result = $this->run($node, sprintf(
            "id -u %s\nid -g %s",
            escapeshellarg($runtimeUser),
            escapeshellarg($runtimeUser),
        ));

        if (! $result->successful()) {
            $message = trim($result->errorOutput().' '.$result->stdout);
            $message = $message !== '' ? $message : "Runtime user '{$runtimeUser}' is unavailable.";

            throw new AppRuntimeUserUnavailableException(
                runtimeUser: $runtimeUser,
                message: $message,
            );
        }

        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $result->stdout)),
            fn (string $line): bool => $line !== '',
        ));

        $uid = $lines[0] ?? '';
        $gid = $lines[1] ?? '';

        if (preg_match('/^\d+$/', $uid) !== 1 || preg_match('/^\d+$/', $gid) !== 1) {
            throw new AppRuntimeContainerApplyException(
                hadExistingContainer: $hadExistingContainer,
                message: "Failed to resolve runtime user '{$runtimeUser}' to numeric UID:GID on node '{$node->name}'.",
            );
        }

        return $container->withDockerUser("{$uid}:{$gid}");
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
     *   on the node (inspect emitted "No such object/container");
     * - Removed when the container existed and was removed;
     * - FailedRemaining when the container existed but could not be removed,
     *   or when the existence probe failed for an unknown reason (Docker
     *   daemon unavailable, permission denied, SSH/remote error). Unknown
     *   probe failures must NOT be reported as clean absence because the
     *   artifact may still be on the node.
     */
    public function remove(Node $node, string $appSlug): AppRuntimeArtifactRemovalOutcome
    {
        $name = "orbit-app-{$appSlug}";

        $inspect = $this->run($node, $this->commands->containerInspect($name));

        if ($inspect->successful() && trim($inspect->stdout) !== '') {
            $result = $this->run($node, $this->commands->containerRemove($name));

            return $result->successful()
                ? AppRuntimeArtifactRemovalOutcome::Removed
                : AppRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        if ($this->isDockerNoSuchObject($inspect)) {
            return AppRuntimeArtifactRemovalOutcome::AlreadyAbsent;
        }

        return AppRuntimeArtifactRemovalOutcome::FailedRemaining;
    }

    /**
     * Tri-state managed runtime config file removal. The php.ini snippet
     * mounted into the FrankenPHP container lives at
     * `/etc/orbit/apps/<slug>.ini` on the node. AlreadyAbsent is only
     * returned when the probe proves the file is missing; sudo/SSH/probe
     * errors are reported as FailedRemaining.
     */
    public function removeRuntimeConfigFile(Node $node, string $appSlug): AppRuntimeArtifactRemovalOutcome
    {
        $path = $this->runtimeConfigPath($appSlug);

        $existence = $this->probeRuntimeConfigExistence($node, $path);

        if ($existence === 'absent') {
            return AppRuntimeArtifactRemovalOutcome::AlreadyAbsent;
        }

        if ($existence !== 'present') {
            return AppRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        $remove = $this->run($node, 'sudo rm -f '.escapeshellarg($path));

        if (! $remove->successful()) {
            return AppRuntimeArtifactRemovalOutcome::FailedRemaining;
        }

        return $this->probeRuntimeConfigExistence($node, $path) === 'absent'
            ? AppRuntimeArtifactRemovalOutcome::Removed
            : AppRuntimeArtifactRemovalOutcome::FailedRemaining;
    }

    /**
     * Probe a remote path for existence and distinguish proven absence from
     * sudo / SSH / remote shell errors. Returns one of `present`, `absent`,
     * or `error` (unknown state).
     */
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

    /**
     * Write or rewrite the managed runtime config file for an app to match
     * the rendered container's expected php.ini snippet. Used by doctor's
     * restore mode for app.runtime_config_missing / app.runtime_config_mismatch.
     */
    public function writeRuntimeConfigFile(Node $node, AppRuntimeContainer $container): void
    {
        $this->runRequired(
            $node,
            $this->renderRuntimeConfigWriteScript($container),
            "write managed runtime config for {$container->appSlug()}",
        );
    }

    public function runtimeConfigPath(string $appSlug): string
    {
        return "/etc/orbit/apps/{$appSlug}.ini";
    }

    private function ensureNetwork(Node $node, AppRuntimeContainer $container): void
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
    private function inspect(Node $node, AppRuntimeContainer $container): ?array
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

    private function createContainer(Node $node, AppRuntimeContainer $container): void
    {
        $this->runRequired(
            $node,
            $this->renderInstallAndRunScript($container),
            "create {$container->name()} container",
        );
    }

    private function renderInstallAndRunScript(AppRuntimeContainer $container): string
    {
        return $this->renderRuntimeConfigWriteScript($container).PHP_EOL.$this->commands->runDetached($container);
    }

    private function renderRuntimeConfigWriteScript(AppRuntimeContainer $container): string
    {
        $phpIniHostPath = $this->findPhpIniMountSource($container);
        $phpIniDirectory = dirname($phpIniHostPath);
        $phpIniContent = $container->phpIniContent();
        $runtimeStateScript = $this->renderRuntimeStateDirectoryScript($container);
        $packagesMountScript = $this->renderPackagesMountDirectoryScript($container);
        $configuredMountScript = $this->renderConfiguredMountDirectoryScript($container);

        return sprintf(
            <<<'SH'
set -e
sudo install -d -m 0755 %s
printf %%s %s | base64 -d | sudo tee %s >/dev/null
%s
%s
%s
SH,
            escapeshellarg($phpIniDirectory),
            escapeshellarg(base64_encode($phpIniContent)),
            escapeshellarg($phpIniHostPath),
            $runtimeStateScript,
            $packagesMountScript,
            $configuredMountScript,
        );
    }

    private function renderRuntimeStateDirectoryScript(AppRuntimeContainer $container): string
    {
        $sources = [];

        foreach ($container->mounts() as $mount) {
            if (! in_array($mount['target'], ['/config', '/data'], true)) {
                continue;
            }

            if ($mount['read_only']) {
                continue;
            }

            $sources[] = $mount['source'];
        }

        $sources = array_values(array_unique($sources));

        if ($sources === []) {
            return '';
        }

        $lines = array_map(
            fn (string $source): string => 'sudo install -d -m 0775 '.escapeshellarg($source),
            $sources,
        );

        $dockerUser = $container->dockerUser();

        if ($dockerUser !== null && $dockerUser !== '') {
            foreach ($sources as $source) {
                $lines[] = 'sudo chown '.escapeshellarg($dockerUser).' '.escapeshellarg($source);
            }
        }

        return implode("\n", $lines);
    }

    private function renderPackagesMountDirectoryScript(AppRuntimeContainer $container): string
    {
        $sources = [];

        foreach ($container->mounts() as $mount) {
            if ($mount['target'] !== AppDevelopmentPackagesMount::Target) {
                continue;
            }

            $sourceUser = AppDevelopmentPackagesMount::userForSafeSource($mount['source']);

            if ($sourceUser === null) {
                throw new RuntimeException("App runtime container {$container->name()} has an unsafe packages mount source.");
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

    private function renderConfiguredMountDirectoryScript(AppRuntimeContainer $container): string
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
                throw new RuntimeException("App runtime container {$container->name()} has an unsafe configured runtime mount source.");
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
    private function builtInMountSources(AppRuntimeContainer $container): array
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
            AppRuntimeContainer::SourceTarget,
            AppRuntimeContainer::PhpIniMountTarget,
            AppDevelopmentPackagesMount::Target,
            '/config',
            '/data',
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

    private function findPhpIniMountSource(AppRuntimeContainer $container): string
    {
        foreach ($container->mounts() as $mount) {
            if ($mount['target'] === AppRuntimeContainer::PhpIniMountTarget) {
                return $mount['source'];
            }
        }

        throw new RuntimeException("App runtime container {$container->name()} is missing its php.ini mount.");
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function matchesSpec(array $inspection, AppRuntimeContainer $container): bool
    {
        $labels = $inspection['Config']['Labels'] ?? [];

        if (! is_array($labels)) {
            return false;
        }

        return ($labels[AppRuntimeContainer::SpecHashLabel] ?? null) === $container->specHash();
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
