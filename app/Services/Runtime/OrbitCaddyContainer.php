<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Tools\CaddyTool;
use InvalidArgumentException;

class OrbitCaddyContainer
{
    public const SpecHashLabel = 'orbit.caddy.spec_hash';

    /**
     * Official upstream Caddy image used as the orbit-caddy runtime.
     *
     * Orbit does not maintain a bespoke Caddy build: the container reads its
     * complete configuration from host bind mounts (`/etc/caddy/Caddyfile`,
     * `/etc/caddy/orbit`, `/etc/caddy/sites`), and the global Caddyfile is
     * rendered on the host by {@see CaddyTool::hostConfigPreparationScript()}.
     * The setup path pulls this image so `docker run --pull never` can find
     * it locally.
     */
    public const Image = 'caddy:2-alpine';

    /**
     * TCP port the orbit-caddy container exposes for private app/workspace
     * backend routes. It is published only on a node's WireGuard address so
     * the listener cannot be reached on a co-located ingress node's public
     * 80/443 sockets.
     */
    public const PrivateBackendPort = 8081;

    /**
     * @param  list<string>  $publishedPorts
     * @param  list<string>  $networkAliases
     * @param  array<string, string>  $extraHosts
     * @param  list<array{source: string, target: string, read_only?: bool}>  $mounts
     */
    public function __construct(
        private readonly string $name,
        private readonly string $image,
        private readonly string $network,
        private readonly string $restartPolicy,
        private readonly array $publishedPorts,
        private readonly array $networkAliases,
        private readonly array $extraHosts,
        private readonly array $mounts,
    ) {}

    public static function default(?OrbitContainerNames $names = null): self
    {
        return self::withPublishedPorts([], $names);
    }

    public static function forPublicIngress(?string $wireGuardAddress = null, ?OrbitContainerNames $names = null): self
    {
        $wireGuardAddress = $wireGuardAddress === null ? '' : trim($wireGuardAddress);

        $ports = [
            '80:80',
            '443:443',
            '443:443/udp',
        ];

        if ($wireGuardAddress !== '') {
            $ports[] = "{$wireGuardAddress}:".self::PrivateBackendPort.':'.self::PrivateBackendPort;
        }

        return self::withPublishedPorts($ports, $names);
    }

    public static function forPrivateNode(
        string $wireGuardAddress,
        ?OrbitContainerNames $names = null,
        ?string $callerFacingAddress = null,
    ): self {
        $wireGuardAddress = trim($wireGuardAddress);

        if ($wireGuardAddress === '') {
            throw new InvalidArgumentException('The orbit-caddy private listener requires a WireGuard address.');
        }

        $ports = [
            "{$wireGuardAddress}:80:80",
            "{$wireGuardAddress}:443:443",
            "{$wireGuardAddress}:443:443/udp",
        ];

        $callerFacingAddress = self::privateCallerFacingAddress($callerFacingAddress, $wireGuardAddress);

        if ($callerFacingAddress !== null) {
            $ports[] = "{$callerFacingAddress}:80:80";
            $ports[] = "{$callerFacingAddress}:443:443";
            $ports[] = "{$callerFacingAddress}:443:443/udp";
        }

        $ports[] = "{$wireGuardAddress}:".self::PrivateBackendPort.':'.self::PrivateBackendPort;

        return self::withPublishedPorts($ports, $names);
    }

    private static function privateCallerFacingAddress(?string $address, string $wireGuardAddress): ?string
    {
        $address = $address === null ? '' : trim($address);

        if ($address === '' || $address === $wireGuardAddress) {
            return null;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) !== false) {
            return null;
        }

        return $address;
    }

    /**
     * @param  list<string>  $publishedPorts
     */
    private static function withPublishedPorts(array $publishedPorts, ?OrbitContainerNames $names = null): self
    {
        $names ??= new OrbitContainerNames;

        return new self(
            name: $names->caddy(),
            image: self::Image,
            network: $names->network(),
            restartPolicy: 'unless-stopped',
            publishedPorts: $publishedPorts,
            networkAliases: [$names->caddy()],
            extraHosts: self::defaultExtraHosts(),
            mounts: self::defaultMounts(),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config, ?OrbitContainerNames $names = null): self
    {
        $default = self::default($names);

        return new self(
            name: self::stringValue($config['name'] ?? null, $default->name()),
            image: self::stringValue($config['image'] ?? null, $default->image()),
            network: self::stringValue($config['network'] ?? null, $default->network()),
            restartPolicy: self::stringValue($config['restart_policy'] ?? null, $default->restartPolicy()),
            publishedPorts: self::stringList($config['published_ports'] ?? null, $default->publishedPorts()),
            networkAliases: self::stringList($config['network_aliases'] ?? null, $default->networkAliases(), sort: true),
            extraHosts: self::stringMap($config['extra_hosts'] ?? null, $default->extraHosts()),
            mounts: self::mountList($config['mounts'] ?? null, $default->mounts()),
        );
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

    /** @return array<string, string> */
    public function environment(): array
    {
        return [];
    }

    /** @return list<array{source: string, target: string, read_only: bool}> */
    public function mounts(): array
    {
        return $this->mounts;
    }

    /**
     * Directories the orbit-caddy update/recreate script must ensure exist on
     * the host before `docker run --mount type=bind`, otherwise container
     * creation fails on fresh nodes where the bind source has never been
     * touched. The `Caddyfile` mount source is a file: its parent directory
     * is what needs to exist before the file itself is written.
     *
     * @return list<string>
     */
    public function hostMountDirectories(): array
    {
        $directories = [];

        foreach ($this->mounts() as $mount) {
            $source = $mount['source'];
            $candidate = str_ends_with($source, 'Caddyfile')
                ? dirname($source)
                : $source;

            if ($candidate === '' || $candidate === '/') {
                continue;
            }

            if (! in_array($candidate, $directories, true)) {
                $directories[] = $candidate;
            }
        }

        return $directories;
    }

    /** @return list<string> */
    public function publishedPorts(): array
    {
        return $this->publishedPorts;
    }

    /** @return list<string> */
    public function networkAliases(): array
    {
        return $this->networkAliases;
    }

    /** @return array<string, string> */
    public function extraHosts(): array
    {
        return $this->extraHosts;
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return [
            'orbit.managed' => 'true',
            'orbit.container.kind' => 'caddy',
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
     *     published_ports: list<string>,
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     network_aliases: list<string>,
     *     extra_hosts: array<string, string>,
     * }
     */
    public function spec(): array
    {
        return [
            'name' => $this->name,
            'image' => $this->image,
            'network' => $this->network,
            'restart_policy' => $this->restartPolicy,
            'published_ports' => $this->publishedPorts,
            'mounts' => $this->mounts(),
            'network_aliases' => $this->networkAliases,
            'extra_hosts' => $this->extraHosts,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function defaultExtraHosts(): array
    {
        return [
            'host.docker.internal' => 'host-gateway',
        ];
    }

    /**
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    private static function defaultMounts(): array
    {
        return [
            ['source' => '/var/lib/orbit/caddy/data', 'target' => '/data/caddy', 'read_only' => false],
            ['source' => '/var/lib/orbit/caddy/config', 'target' => '/config/caddy', 'read_only' => false],
            ['source' => '/etc/caddy/Caddyfile', 'target' => '/etc/caddy/Caddyfile', 'read_only' => true],
            ['source' => '/etc/caddy/orbit', 'target' => '/etc/caddy/orbit', 'read_only' => true],
            ['source' => '/etc/caddy/sites', 'target' => '/etc/caddy/sites', 'read_only' => true],
            ['source' => '/etc/orbit', 'target' => '/etc/orbit', 'read_only' => true],
            ['source' => '/home', 'target' => '/home', 'read_only' => true],
            ['source' => '/run/php', 'target' => '/run/php', 'read_only' => false],
        ];
    }

    private static function stringValue(mixed $value, string $default): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function stringList(mixed $value, array $default, bool $sort = false): array
    {
        if (! is_array($value)) {
            return $default;
        }

        $list = array_values(array_unique(array_filter(
            array_map(
                fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
                $value,
            ),
            fn (string $item): bool => $item !== '',
        )));

        if ($list === []) {
            return $default;
        }

        if ($sort) {
            sort($list);
        }

        return $list;
    }

    /**
     * @param  array<string, string>  $default
     * @return array<string, string>
     */
    private static function stringMap(mixed $value, array $default): array
    {
        if (! is_array($value)) {
            return $default;
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            if (! is_scalar($item)) {
                continue;
            }

            $resolved = trim((string) $item);

            if ($resolved === '') {
                continue;
            }

            $map[trim($key)] = $resolved;
        }

        if ($map === []) {
            return $default;
        }

        ksort($map);

        return $map;
    }

    /**
     * @param  list<array{source: string, target: string, read_only: bool}>  $default
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    private static function mountList(mixed $value, array $default): array
    {
        if (! is_array($value)) {
            return $default;
        }

        $mounts = [];

        foreach ($value as $mount) {
            if (! is_array($mount)) {
                continue;
            }

            $source = $mount['source'] ?? null;
            $target = $mount['target'] ?? null;

            if (! is_scalar($source) || ! is_scalar($target)) {
                continue;
            }

            $source = trim((string) $source);
            $target = trim((string) $target);

            if ($source === '' || $target === '') {
                continue;
            }

            $mounts[] = [
                'source' => $source,
                'target' => $target,
                'read_only' => (bool) ($mount['read_only'] ?? false),
            ];
        }

        return $mounts === [] ? $default : $mounts;
    }
}
