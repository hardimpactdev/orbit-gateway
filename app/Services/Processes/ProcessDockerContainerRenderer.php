<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\OrbitContainerNames;
use InvalidArgumentException;

final readonly class ProcessDockerContainerRenderer
{
    public function __construct(
        private PhpRuntimePolicy $phpRuntimePolicy,
        private OrbitContainerNames $names,
    ) {}

    public function render(App $app, Process $process, ?Workspace $workspace = null): ProcessDockerContainer
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return $this->renderNodeProcess($process->owner, $process);
        }

        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            throw new InvalidArgumentException(
                "App '{$app->name}' uses runtime kind '{$app->runtime_kind->value}' and cannot back a Docker process runtime unit.",
            );
        }

        $phpVersion = $this->resolvePhpVersion($app, $workspace);

        if ($phpVersion === null) {
            throw new InvalidArgumentException(
                "Process '{$process->name}' on app '{$app->name}' has no resolvable PHP version; cannot render Docker process runtime container.",
            );
        }

        $sourcePath = $this->resolveSourcePath($app, $workspace, $process);

        $policy = $this->phpRuntimePolicy->forVersion($phpVersion);

        $name = $this->containerName($app, $process, $workspace);

        return new ProcessDockerContainer(
            name: $name,
            image: $policy->image,
            network: $this->names->network(),
            restartPolicy: $process->restart_policy->toDocker(),
            appSlug: $app->name,
            workspaceSlug: $workspace?->name,
            processSlug: $process->name,
            workingDirectory: ProcessDockerContainer::SourceTarget,
            command: $process->command,
            environment: $this->environmentFor($app, $process, $workspace, $phpVersion),
            mounts: [
                [
                    'source' => $sourcePath,
                    'target' => ProcessDockerContainer::SourceTarget,
                    'read_only' => false,
                ],
            ],
            networkAliases: [$name],
        );
    }

    public function containerName(App $app, Process $process, ?Workspace $workspace = null): string
    {
        $process->loadMissing('owner');

        $configuredName = $this->configuredContainerName($process);

        if ($configuredName !== null) {
            return $configuredName;
        }

        if ($process->owner instanceof Node) {
            return $this->assertIdentitySlug($process->name);
        }

        $this->assertIdentitySlug($app->name);
        $this->assertIdentitySlug($process->name);

        $scope = 'main';

        if ($workspace instanceof Workspace) {
            $this->assertIdentitySlug($workspace->name);
            $scope = $workspace->name;
        }

        return "orbit_{$app->name}_{$scope}_{$process->name}";
    }

    private function configuredContainerName(Process $process): ?string
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $name = $this->optionalConfigString($config, 'container_name');

        if ($name === null) {
            return null;
        }

        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name)) {
            throw new InvalidArgumentException("Unsafe Docker process runtime container name: {$name}");
        }

        return $name;
    }

    private function renderNodeProcess(Node $node, Process $process): ProcessDockerContainer
    {
        $name = $this->assertIdentitySlug($process->name);
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $command = trim($process->command);

        if ($command === '') {
            throw new InvalidArgumentException(
                "Node process '{$process->name}' has no command; cannot render Docker process runtime container.",
            );
        }

        return new ProcessDockerContainer(
            name: $name,
            image: $this->requiredConfigString($config, 'image', $process),
            network: $this->optionalConfigString($config, 'network') ?? $this->names->network(),
            restartPolicy: $process->restart_policy->toDocker(),
            appSlug: $node->name,
            workspaceSlug: null,
            processSlug: $process->name,
            workingDirectory: $this->optionalConfigString($config, 'working_directory') ?? '/',
            command: $command,
            environment: $this->stringMap($config['environment'] ?? []),
            mounts: $this->mounts($config['mounts'] ?? []),
            networkAliases: array_values(array_unique([
                $name,
                ...$this->stringList($config['network_aliases'] ?? []),
            ])),
        );
    }

    private function resolvePhpVersion(App $app, ?Workspace $workspace): ?string
    {
        if ($workspace instanceof Workspace) {
            $version = $workspace->effectivePhpVersion();

            return is_string($version) && trim($version) !== '' ? trim($version) : null;
        }

        $version = $app->php_version;

        return is_string($version) && trim($version) !== '' ? trim($version) : null;
    }

    private function resolveSourcePath(App $app, ?Workspace $workspace, Process $process): string
    {
        $path = $workspace instanceof Workspace ? $workspace->path : $app->path;
        $path = rtrim((string) $path, '/');

        if ($path === '') {
            throw new InvalidArgumentException(
                "Process '{$process->name}' on app '{$app->name}' has no source path; cannot render Docker process runtime container.",
            );
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function environmentFor(App $app, Process $process, ?Workspace $workspace, string $phpVersion): array
    {
        $url = $workspace instanceof Workspace ? $workspace->url() : $app->url();
        $host = $this->hostFromUrl($url, $app, $workspace);
        $home = '/root';
        $tlsBase = '/etc/orbit/certs/'.$host;

        $environment = [
            'PATH' => '/app/vendor/bin:/app/node_modules/.bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => $home,
            'APP_URL' => $url,
            'VITE_APP_URL' => $url,
            'VITE_VALET_HOST' => $host,
            'VITE_DEV_SERVER_KEY' => $tlsBase.'.key',
            'VITE_DEV_SERVER_CERT' => $tlsBase.'.crt',
            'ORBIT_APP' => $app->name,
            'ORBIT_PHP_VERSION' => $phpVersion,
        ];

        if ($workspace instanceof Workspace) {
            $environment['ORBIT_WORKSPACE'] = $workspace->name;
        }

        return $environment;
    }

    private function hostFromUrl(string $url, App $app, ?Workspace $workspace): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return $host;
        }

        $stripped = preg_replace('#^https?://#', '', $url);

        if (is_string($stripped) && $stripped !== '') {
            return $stripped;
        }

        return $workspace instanceof Workspace ? "{$workspace->name}.{$app->name}" : $app->name;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function requiredConfigString(array $config, string $key, Process $process): string
    {
        $value = $this->optionalConfigString($config, $key);

        if ($value !== null) {
            return $value;
        }

        throw new InvalidArgumentException(
            "Node process '{$process->name}' is missing runtime_config.{$key}; cannot render Docker process runtime container.",
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function optionalConfigString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            if (is_scalar($item)) {
                $map[$key] = (string) $item;
            }
        }

        return $map;
    }

    /**
     * @return list<array{source: string, target: string, read_only?: bool}>
     */
    private function mounts(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(function (mixed $mount): ?array {
                if (! is_array($mount)) {
                    return null;
                }

                $source = $mount['source'] ?? null;
                $target = $mount['target'] ?? null;

                if (! is_string($source) || ! is_string($target)) {
                    return null;
                }

                return [
                    'source' => $source,
                    'target' => $target,
                    'read_only' => (bool) ($mount['read_only'] ?? false),
                ];
            }, $value),
        ));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): string => is_string($item) ? trim($item) : '', $value),
            fn (string $item): bool => $item !== '',
        ));
    }

    private function assertIdentitySlug(string $value): string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $value)) {
            throw new InvalidArgumentException("Unsafe Docker process runtime identity segment: {$value}");
        }

        return $value;
    }
}
