<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\RemoteShell;
use App\Models\Node;

final class SshdHardenedInstaller implements SecurityInstaller
{
    public function installFor(Node $node, RemoteShell $shell): InstallReport
    {
        $result = $shell->run($node, $this->script($node), [
            'timeout' => 120,
            'throw' => false,
        ]);

        return new InstallReport(
            successful: $result->successful(),
            summary: $result->successful()
                ? 'Installed hardened SSH daemon configuration.'
                : 'Failed to install hardened SSH daemon configuration.',
            details: [
                'exit_code' => $result->exitCode,
            ],
        );
    }

    public function script(Node $node): string
    {
        $wireguardAddress = trim((string) $node->wireguard_address);
        $managedUser = trim((string) $node->user);

        return sprintf(
            <<<'SH_WRAP'
            set -euo pipefail
            WG_ADDRESS=%s
            MANAGED_USER=%s
            if [ -z "$WG_ADDRESS" ]; then
                echo "Orbit WireGuard address is missing." >&2
                exit 1
            fi
            if [ -z "$MANAGED_USER" ]; then
                echo "Orbit managed SSH user is missing." >&2
                exit 1
            fi
            sudo install -d -m 0755 /etc/ssh/sshd_config.d
            sudo tee /etc/ssh/sshd_config.d/99-orbit-hardening.conf > /dev/null <<EOF
            # Managed by Orbit.
            PermitRootLogin no
            PasswordAuthentication no
            KbdInteractiveAuthentication no
            ChallengeResponseAuthentication no
            PubkeyAuthentication yes
            MaxAuthTries 3
            X11Forwarding no
            AllowUsers $MANAGED_USER
            ListenAddress %s
            ListenAddress 127.0.0.1
            EOF
            sudo chmod 0644 /etc/ssh/sshd_config.d/99-orbit-hardening.conf
            sudo sshd -t
            sudo systemctl reload ssh 2>/dev/null || sudo systemctl reload sshd
            SH_WRAP,
            escapeshellarg($wireguardAddress),
            escapeshellarg($managedUser),
            $wireguardAddress,
        );
    }
}
