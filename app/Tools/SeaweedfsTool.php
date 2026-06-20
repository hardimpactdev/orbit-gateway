<?php

declare(strict_types=1);

namespace App\Tools;

final class SeaweedfsTool extends BaseTool
{
    public function slug(): string
    {
        return 'seaweedfs';
    }

    #[\Override]
    public function requiredNodeRole(): string
    {
        return 's3';
    }

    #[\Override]
    public function category(): string
    {
        return 'storage';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'update', 'credentials', 'safe-fix', 'safe-adopt'];
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'docker',
            'version_command' => 'docker --version',
            'container' => 'orbit-seaweedfs',
            'image' => 'chrislusf/seaweedfs:4.33',
            'repair_commands' => [
                'lifecycle_running' => 'docker start '.escapeshellarg('orbit-seaweedfs'),
                'lifecycle_stopped' => 'docker stop '.escapeshellarg('orbit-seaweedfs'),
                'lifecycle_restarted' => 'docker restart '.escapeshellarg('orbit-seaweedfs'),
            ],
        ];
    }
}
