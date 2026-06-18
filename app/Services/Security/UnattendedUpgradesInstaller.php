<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\RemoteShell;
use App\Models\Node;
use Orbit\Core\Updates\UnattendedUpgradesAptConfig;

final class UnattendedUpgradesInstaller implements SecurityInstaller
{
    public function installFor(Node $node, RemoteShell $shell): InstallReport
    {
        $result = $shell->run($node, $this->script(), [
            'timeout' => 900,
            'throw' => false,
        ]);

        return new InstallReport(
            successful: $result->successful(),
            summary: $result->successful()
                ? 'Installed unattended security upgrades.'
                : 'Failed to install unattended security upgrades.',
            details: [
                'exit_code' => $result->exitCode,
            ],
        );
    }

    public function script(): string
    {
        $config = new UnattendedUpgradesAptConfig;
        $autoUpgrades = rtrim($config->autoUpgrades(), "\n");
        $unattendedUpgrades = rtrim($config->unattendedUpgrades(), "\n");

        return <<<SH
set -euo pipefail
sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq unattended-upgrades
sudo tee /etc/apt/apt.conf.d/20auto-upgrades > /dev/null <<'EOF'
{$autoUpgrades}
EOF
sudo tee /etc/apt/apt.conf.d/50unattended-upgrades > /dev/null <<'EOF'
{$unattendedUpgrades}
EOF
SH;
    }
}
