<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\RemoteShell;
use App\Models\Node;

final class HomeDirectoryLockdownInstaller implements SecurityInstaller
{
    public function installFor(Node $node, RemoteShell $shell): InstallReport
    {
        $result = $shell->run($node, $this->script(), [
            'timeout' => 60,
            'throw' => false,
        ]);

        return new InstallReport(
            successful: $result->successful(),
            summary: $result->successful()
                ? 'Locked down /home/orbit permissions.'
                : 'Failed to lock down /home/orbit permissions.',
            details: [
                'exit_code' => $result->exitCode,
            ],
        );
    }

    public function script(): string
    {
        return <<<'SH'
set -euo pipefail
sudo install -d -m 0700 -o orbit -g orbit /home/orbit
sudo install -d -m 0700 -o orbit -g orbit /home/orbit/.ssh
sudo install -d -m 0755 -o orbit -g orbit /home/orbit/.config /home/orbit/.config/orbit /home/orbit/.config/orbit/logs /home/orbit/.config/orbit/php
sudo chmod 0700 /home/orbit /home/orbit/.ssh
sudo chown orbit:orbit /home/orbit /home/orbit/.ssh /home/orbit/.config /home/orbit/.config/orbit /home/orbit/.config/orbit/logs /home/orbit/.config/orbit/php
if [ -f /home/orbit/.ssh/authorized_keys ]; then
    sudo chmod 0600 /home/orbit/.ssh/authorized_keys
    sudo chown orbit:orbit /home/orbit/.ssh/authorized_keys
fi
SH;
    }
}
