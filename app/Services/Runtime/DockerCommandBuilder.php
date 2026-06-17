<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Services\Apps\AppRuntimeContainer;
use App\Services\Processes\ProcessDockerContainer;
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use InvalidArgumentException;

class DockerCommandBuilder
{
    public function networkInspect(string $network): string
    {
        return 'docker network inspect '.$this->quote($network);
    }

    public function networkCreate(string $network): string
    {
        return implode(' ', [
            'docker network create',
            '--label',
            $this->quote('orbit.managed=true'),
            '--label',
            $this->quote('orbit.network.kind=runtime'),
            $this->quote($network),
        ]);
    }

    public function containerInspect(string $name): string
    {
        return 'docker container inspect --format '.$this->quote('{{json .}}').' '.$this->quote($name);
    }

    public function containerRemove(string $name): string
    {
        return 'docker rm -f '.$this->quote($name);
    }

    public function containerStart(string $name): string
    {
        return 'docker start '.$this->quote($name);
    }

    public function containerStop(string $name): string
    {
        return 'docker stop '.$this->quote($name);
    }

    public function containerRestart(string $name): string
    {
        return 'docker restart '.$this->quote($name);
    }

    public function runDetached(OrbitGatewayContainer|OrbitCaddyContainer|AppRuntimeContainer|WorkspaceRuntimeContainer|ProcessDockerContainer|WebSocketRuntimeContainer $container): string
    {
        return $this->buildRunOrCreate('docker run -d', $container);
    }

    public function createIdle(ProcessDockerContainer $container): string
    {
        // `docker create` produces a container in the Created state without
        // starting it. Process runtime units honor the --start contract from
        // process:add by deferring the actual start to a separate lifecycle
        // call. App/Workspace/Caddy runtime containers stay on `docker run -d`
        // because the gateway/proxy must be running once rendered.
        return $this->buildRunOrCreate('docker create', $container);
    }

    private function buildRunOrCreate(string $prefix, OrbitGatewayContainer|OrbitCaddyContainer|AppRuntimeContainer|WorkspaceRuntimeContainer|ProcessDockerContainer|WebSocketRuntimeContainer $container): string
    {
        $parts = [
            $prefix,
            '--pull',
            $this->quote('never'),
            '--name',
            $this->quote($container->name()),
            '--restart',
            $this->quote($container->restartPolicy()),
            '--network',
            $this->quote($this->networkFor($container)),
        ];

        if ($container instanceof OrbitCaddyContainer && ! $this->usesE2eNodeNetwork($container)) {
            foreach ($container->publishedPorts() as $port) {
                $parts[] = '--publish';
                $parts[] = $this->quote($port);
            }

            foreach ($container->extraHosts() as $host => $address) {
                $parts[] = '--add-host';
                $parts[] = $this->quote("{$host}:{$address}");
            }
        }

        if ($container instanceof ProcessDockerContainer || $container instanceof WebSocketRuntimeContainer) {
            $parts[] = '--workdir';
            $parts[] = $this->quote($container->workingDirectory());
            $parts[] = '--entrypoint';
            $parts[] = $this->quote('sh');
        }

        if ($container instanceof AppRuntimeContainer && $container->dockerUser() !== null) {
            $parts[] = '--user';
            $parts[] = $this->quote($this->numericDockerUser($container->dockerUser()));
        }

        if (! $this->usesE2eNodeNetwork($container)) {
            foreach ($container->networkAliases() as $alias) {
                $parts[] = '--network-alias';
                $parts[] = $this->quote($alias);
            }
        }

        foreach ($container->labels() as $key => $value) {
            $parts[] = '--label';
            $parts[] = $this->quote("{$key}={$value}");
        }

        foreach ($container->environment() as $key => $value) {
            $parts[] = '--env';
            $parts[] = $this->quote("{$key}={$value}");
        }

        foreach ($container->mounts() as $mount) {
            $parts[] = '--mount';
            $parts[] = $this->quote($this->mountSpec($mount));
        }

        $parts[] = $this->quote($container->image());

        if ($container instanceof ProcessDockerContainer || $container instanceof WebSocketRuntimeContainer) {
            // Runtime process commands are stored as single shell strings
            // (e.g. "php artisan queue:work --tries=3"). Run them through
            // `sh -lc <cmd>` so the in-container shell parses tokens,
            // redirections, and shell operators instead of Docker exec-ing a
            // literal binary named after the whole string.
            $parts[] = $this->quote('-lc');
            $parts[] = $this->quote($container->command());
        }

        return implode(' ', $parts);
    }

    private function networkFor(OrbitGatewayContainer|OrbitCaddyContainer|AppRuntimeContainer|WorkspaceRuntimeContainer|ProcessDockerContainer|WebSocketRuntimeContainer $container): string
    {
        $nodeContainer = $this->e2eNodeContainerFor($container);

        if ($nodeContainer !== null) {
            return 'container:'.$nodeContainer;
        }

        return $container->network();
    }

    private function numericDockerUser(string $dockerUser): string
    {
        $dockerUser = trim($dockerUser);

        if (preg_match('/^\d+:\d+$/', $dockerUser) !== 1) {
            throw new InvalidArgumentException('Docker app runtime users must be numeric UID:GID values.');
        }

        return $dockerUser;
    }

    private function usesE2eNodeNetwork(OrbitGatewayContainer|OrbitCaddyContainer|AppRuntimeContainer|WorkspaceRuntimeContainer|ProcessDockerContainer|WebSocketRuntimeContainer $container): bool
    {
        return ($container instanceof OrbitCaddyContainer || $container instanceof ProcessDockerContainer || $container instanceof WebSocketRuntimeContainer)
            && $this->e2eNodeContainerFor($container) !== null;
    }

    private function e2eNodeContainerFor(OrbitGatewayContainer|OrbitCaddyContainer|AppRuntimeContainer|WorkspaceRuntimeContainer|ProcessDockerContainer|WebSocketRuntimeContainer $container): ?string
    {
        if ($this->e2eDockerNetwork() === null) {
            return null;
        }

        if ($container instanceof OrbitCaddyContainer && str_ends_with($container->name(), '-orbit-caddy')) {
            return substr($container->name(), 0, -strlen('-orbit-caddy')) ?: null;
        }

        if ($container instanceof WebSocketRuntimeContainer) {
            $position = strpos($container->name(), '-orbit-websocket-');

            if ($position !== false) {
                return substr($container->name(), 0, $position) ?: null;
            }
        }

        return $this->e2eNodeContainer();
    }

    private function e2eDockerNetwork(): ?string
    {
        $network = getenv('ORBIT_E2E_DOCKER_NETWORK');

        return is_string($network) && trim($network) !== '' ? trim($network) : null;
    }

    private function e2eNodeContainer(): ?string
    {
        $nodeContainer = getenv('ORBIT_NODE_CONTAINER');

        return is_string($nodeContainer) && trim($nodeContainer) !== '' ? trim($nodeContainer) : null;
    }

    /**
     * @param  array{source: string, target: string, read_only: bool}  $mount
     */
    private function mountSpec(array $mount): string
    {
        $fields = [
            'type=bind',
            $this->mountField('source', $mount['source']),
            $this->mountField('target', $mount['target']),
        ];

        if ($mount['read_only']) {
            $fields[] = 'readonly';
        }

        return implode(',', $fields);
    }

    private function mountField(string $key, string $value): string
    {
        $field = "{$key}={$value}";

        if (str_contains($field, ',') || str_contains($field, '"')) {
            return '"'.str_replace('"', '""', $field).'"';
        }

        return $field;
    }

    private function quote(string $value): string
    {
        return escapeshellarg($value);
    }
}
