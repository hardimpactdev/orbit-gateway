<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use InvalidArgumentException;

class OrbitGatewayContainerRenderer
{
    public function __construct(
        private readonly OrbitContainerNames $names,
    ) {}

    /**
     * @param  array<string, scalar|null>  $environment
     */
    public function render(
        string $orbitCheckoutPath,
        ?string $gatewayConfigRoot = null,
        string $image = 'orbit-gateway:current',
        array $environment = [],
    ): OrbitGatewayContainer {
        $resolvedEnvironment = $this->stringEnvironment($environment);
        $resolvedEnvironment['ORBIT_SOURCE_PATH'] = OrbitGatewayContainer::SourcePath;

        if ($gatewayConfigRoot !== null) {
            $resolvedEnvironment['ORBIT_CONFIG_ROOT'] = $this->normalizePath($gatewayConfigRoot, 'gatewayConfigRoot');
            $resolvedEnvironment['ORBIT_TRUST_WIREGUARD_PROXY_HEADER'] = '1';
        }

        $mounts = [
            [
                'source' => $this->normalizePath($orbitCheckoutPath, 'orbitCheckoutPath'),
                'target' => OrbitGatewayContainer::SourcePath,
                'read_only' => false,
            ],
            [
                'source' => '/var/run/docker.sock',
                'target' => '/var/run/docker.sock',
                'read_only' => false,
            ],
        ];

        if ($gatewayConfigRoot !== null) {
            $resolvedGatewayConfigRoot = $this->normalizePath($gatewayConfigRoot, 'gatewayConfigRoot');

            $mounts[] = [
                'source' => $resolvedGatewayConfigRoot,
                'target' => $resolvedGatewayConfigRoot,
                'read_only' => false,
            ];
        }

        return new OrbitGatewayContainer(
            name: $this->names->gateway(),
            image: $image,
            network: $this->names->network(),
            restartPolicy: 'unless-stopped',
            environment: $resolvedEnvironment,
            mounts: $mounts,
            networkAliases: [$this->names->gateway()],
        );
    }

    /**
     * @param  array<string, scalar|null>  $environment
     * @return array<string, string>
     */
    private function stringEnvironment(array $environment): array
    {
        $resolved = [];

        foreach ($environment as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException('Gateway container environment keys must be non-empty strings.');
            }

            $resolved[$key] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                $value === null => '',
                default => (string) $value,
            };
        }

        return $resolved;
    }

    private function normalizePath(string $path, string $field): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new InvalidArgumentException("Gateway container {$field} cannot be empty.");
        }

        if ($path === '/') {
            return $path;
        }

        return rtrim($path, '/');
    }
}
