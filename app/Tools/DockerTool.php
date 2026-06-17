<?php

declare(strict_types=1);

namespace App\Tools;

final class DockerTool extends BaseTool
{
    public function slug(): string
    {
        return 'docker';
    }

    #[\Override]
    public function category(): string
    {
        return 'always';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['safe-fix', 'safe-adopt'];
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'docker',
            'version_command' => 'docker --version',
            'service' => 'docker',
            'repair_commands' => $this->serviceRepairCommands('docker', restart: true),
        ];
    }
}
