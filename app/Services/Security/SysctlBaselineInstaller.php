<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\RemoteShell;
use App\Models\Node;

final class SysctlBaselineInstaller implements SecurityInstaller
{
    public function installFor(Node $node, RemoteShell $shell): InstallReport
    {
        $result = $shell->run($node, $this->script(), [
            'timeout' => 120,
            'throw' => false,
        ]);

        return new InstallReport(
            successful: $result->successful(),
            summary: $result->successful()
                ? 'Installed Orbit sysctl baseline.'
                : 'Failed to install Orbit sysctl baseline.',
            details: [
                'exit_code' => $result->exitCode,
            ],
        );
    }

    public function script(): string
    {
        return <<<'SH'
set -euo pipefail
sudo tee /etc/sysctl.d/60-orbit.conf > /dev/null <<'EOF'
net.ipv4.conf.all.rp_filter=1
net.ipv4.conf.default.rp_filter=1
net.ipv4.tcp_syncookies=1
net.ipv4.conf.all.accept_redirects=0
net.ipv6.conf.all.accept_redirects=0
net.ipv4.conf.all.accept_source_route=0
net.ipv6.conf.all.accept_source_route=0
net.ipv4.conf.all.send_redirects=0
kernel.randomize_va_space=2
EOF
sudo chmod 0644 /etc/sysctl.d/60-orbit.conf
sudo sysctl --system >/dev/null
SH;
    }
}
