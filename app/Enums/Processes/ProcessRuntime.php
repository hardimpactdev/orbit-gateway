<?php

declare(strict_types=1);

namespace App\Enums\Processes;

use App\Models\App;

enum ProcessRuntime: string
{
    case Docker = 'docker';
    case DockerSwarm = 'docker-swarm';
    case Systemd = 'systemd';

    public static function defaultForApp(App $app): self
    {
        return self::Systemd;
    }

    public function requiresNodeOwner(): bool
    {
        return match ($this) {
            self::DockerSwarm => true,
            self::Docker, self::Systemd => false,
        };
    }

    public function nodeOwnerViolationReason(): ?string
    {
        return match ($this) {
            self::DockerSwarm => 'docker_swarm_requires_node_owned_process',
            self::Docker, self::Systemd => null,
        };
    }

    public function nodeOwnerViolationMessage(): ?string
    {
        return match ($this) {
            self::DockerSwarm => 'The docker-swarm runtime is only valid for node-owned processes.',
            self::Docker, self::Systemd => null,
        };
    }

    public function appWorkspaceCommandViolationReason(): ?string
    {
        return match ($this) {
            self::Docker => 'docker_runtime_requires_service_or_managed_process',
            self::DockerSwarm => 'docker_swarm_requires_node_owned_process',
            self::Systemd => null,
        };
    }

    public function appWorkspaceCommandViolationMessage(): ?string
    {
        return match ($this) {
            self::Docker => 'The docker runtime is only valid for service definitions or Orbit-managed runtime processes.',
            self::DockerSwarm => $this->nodeOwnerViolationMessage(),
            self::Systemd => null,
        };
    }
}
