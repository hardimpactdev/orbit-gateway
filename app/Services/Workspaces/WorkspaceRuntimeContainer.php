<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use InvalidArgumentException;

class WorkspaceRuntimeContainer
{
    public const SourceTarget = '/app';

    public const PhpIniMountTarget = '/usr/local/etc/php/conf.d/zz-orbit.ini';

    public const SpecHashLabel = 'orbit.workspace.spec_hash';

    /** @var array<string, string> */
    private readonly array $environment;

    /** @var list<array{source: string, target: string, read_only: bool}> */
    private readonly array $mounts;

    /** @var list<string> */
    private readonly array $networkAliases;

    /** @var array<string, string> */
    private readonly array $phpIni;

    /**
     * @param  array<string, string>  $environment
     * @param  list<array{source: string, target: string, read_only?: bool}>  $mounts
     * @param  list<string>  $networkAliases
     * @param  array<string, string>  $phpIni
     */
    public function __construct(
        private readonly string $name,
        private readonly string $image,
        private readonly string $network,
        private readonly string $restartPolicy,
        private readonly string $appSlug,
        private readonly string $workspaceSlug,
        array $environment,
        array $mounts,
        array $networkAliases,
        array $phpIni,
    ) {
        $this->environment = $this->normalizeEnvironment($environment);
        $this->mounts = $this->normalizeMounts($mounts);
        $this->networkAliases = $this->normalizeNetworkAliases($networkAliases);
        $this->phpIni = $this->normalizePhpIni($phpIni);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function image(): string
    {
        return $this->image;
    }

    public function network(): string
    {
        return $this->network;
    }

    public function restartPolicy(): string
    {
        return $this->restartPolicy;
    }

    public function appSlug(): string
    {
        return $this->appSlug;
    }

    public function workspaceSlug(): string
    {
        return $this->workspaceSlug;
    }

    /** @return array<string, string> */
    public function environment(): array
    {
        return $this->environment;
    }

    /** @return list<array{source: string, target: string, read_only: bool}> */
    public function mounts(): array
    {
        return $this->mounts;
    }

    /** @return list<string> */
    public function networkAliases(): array
    {
        return $this->networkAliases;
    }

    /** @return array<string, string> */
    public function phpIni(): array
    {
        return $this->phpIni;
    }

    public function phpIniContent(): string
    {
        $lines = [];

        foreach ($this->phpIni as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return [
            'orbit.managed' => 'true',
            'orbit.container.kind' => 'workspace-runtime',
            'orbit.app' => $this->appSlug,
            'orbit.workspace' => $this->workspaceSlug,
            self::SpecHashLabel => $this->specHash(),
        ];
    }

    public function specHash(): string
    {
        return hash('sha256', json_encode($this->spec(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{
     *     name: string,
     *     image: string,
     *     network: string,
     *     restart_policy: string,
     *     app_slug: string,
     *     workspace_slug: string,
     *     environment: array<string, string>,
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     network_aliases: list<string>,
     *     php_ini: array<string, string>
     * }
     */
    public function spec(): array
    {
        return [
            'name' => $this->name,
            'image' => $this->image,
            'network' => $this->network,
            'restart_policy' => $this->restartPolicy,
            'app_slug' => $this->appSlug,
            'workspace_slug' => $this->workspaceSlug,
            'environment' => $this->environment,
            'mounts' => $this->mounts,
            'network_aliases' => $this->networkAliases,
            'php_ini' => $this->phpIni,
        ];
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    private function normalizeEnvironment(array $environment): array
    {
        ksort($environment);

        return $environment;
    }

    /**
     * @param  list<array{source: string, target: string, read_only?: bool}>  $mounts
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    private function normalizeMounts(array $mounts): array
    {
        return array_map(function (array $mount): array {
            $source = trim($mount['source']);
            $target = trim($mount['target']);

            if ($source === '' || $target === '') {
                throw new InvalidArgumentException('Workspace runtime container mounts require source and target paths.');
            }

            return [
                'source' => $source,
                'target' => $target,
                'read_only' => (bool) ($mount['read_only'] ?? false),
            ];
        }, $mounts);
    }

    /**
     * @param  list<string>  $networkAliases
     * @return list<string>
     */
    private function normalizeNetworkAliases(array $networkAliases): array
    {
        $aliases = array_values(array_unique(array_filter(
            array_map(trim(...), $networkAliases),
            fn (string $alias): bool => $alias !== '',
        )));

        sort($aliases);

        return $aliases;
    }

    /**
     * @param  array<string, string>  $phpIni
     * @return array<string, string>
     */
    private function normalizePhpIni(array $phpIni): array
    {
        ksort($phpIni);

        return $phpIni;
    }
}
