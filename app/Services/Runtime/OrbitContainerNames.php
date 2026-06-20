<?php

declare(strict_types=1);

namespace App\Services\Runtime;

class OrbitContainerNames
{
    public function __construct(
        private readonly ?string $nodeScope = null,
    ) {}

    public static function forNodeScope(string $nodeScope): self
    {
        return new self($nodeScope);
    }

    public function gateway(): string
    {
        $container = getenv('ORBIT_GATEWAY_CONTAINER');

        if (is_string($container) && $container !== '') {
            return $container;
        }

        return 'orbit-gateway';
    }

    public function caddy(): string
    {
        $network = $this->e2eDockerNetwork();
        $nodeScope = $this->nodeScope ?? $this->e2eNodeContainer();

        if ($network !== null && $nodeScope !== null) {
            return $this->e2eNodeScopedName($network, $nodeScope, 'orbit-caddy');
        }

        return 'orbit-caddy';
    }

    public function network(): string
    {
        $network = $this->e2eDockerNetwork();

        if ($network !== null) {
            return $network;
        }

        return 'orbit-network';
    }

    public function e2eScopedName(string $name): string
    {
        $network = $this->e2eDockerNetwork();
        $nodeScope = $this->nodeScope ?? $this->e2eNodeContainer();

        if ($network === null || $nodeScope === null) {
            return $name;
        }

        return $this->e2eNodeScopedName($network, $nodeScope, $name);
    }

    private function e2eNodeScopedName(string $network, string $nodeScope, string $name): string
    {
        if (str_starts_with($nodeScope, "{$network}-")) {
            return $this->dockerName($nodeScope, $name);
        }

        return $this->dockerName($network, $nodeScope, $name);
    }

    private function e2eDockerNetwork(): ?string
    {
        $network = getenv('ORBIT_E2E_DOCKER_NETWORK');

        if (! is_string($network) || trim($network) === '') {
            return null;
        }

        return $this->sanitizeDockerName(trim($network));
    }

    private function e2eNodeContainer(): ?string
    {
        $nodeContainer = getenv('ORBIT_NODE_CONTAINER');

        if (! is_string($nodeContainer) || trim($nodeContainer) === '') {
            return null;
        }

        return $this->sanitizeDockerName(trim($nodeContainer));
    }

    private function dockerName(string ...$parts): string
    {
        return implode('-', array_map($this->sanitizeDockerName(...), $parts));
    }

    private function sanitizeDockerName(string $value): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', trim($value)) ?? '';

        return trim($sanitized, '-');
    }
}
