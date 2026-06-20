<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Doctor\AdoptResult;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\AdoptAction;
use App\Enums\DriftKind;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Security\PublicSshDenyInstaller;
use App\Services\Security\SshdHardenedInstaller;
use App\Services\Security\SshHostKeyPinner;
use App\Services\Security\SysctlBaselineInstaller;
use RuntimeException;
use Throwable;

final readonly class NodeSecurityPostureProbe
{
    public function __construct(private ?RemoteShell $remoteShell = null) {}

    /**
     * @return list<DriftEntry>
     */
    public function diff(Node $node): array
    {
        if (! $this->appliesTo($node)) {
            return [];
        }

        $drift = [
            ...$this->recordDrift($node),
            ...$this->firewallDrift($node),
        ];

        if (
            $this->remoteShell instanceof RemoteShell
            && is_string($node->wireguard_address)
            && $node->wireguard_address !== ''
        ) {
            $drift = [
                ...$drift,
                ...$this->remoteDrift($node),
            ];
        }

        return $drift;
    }

    public function snapshotForAdopt(Node $node, bool $includeHostKey = false): ProbeSnapshot
    {
        if (! $includeHostKey || ! $this->hostKeyMissing($node)) {
            return new ProbeSnapshot([]);
        }

        return new ProbeSnapshot([
            $this->hostKeyKey($node) => [
                'host' => $node->host,
                'pin_mode' => 'tofu',
            ],
        ]);
    }

    /**
     * @return list<AdoptResult>
     */
    public function adopt(Node $node, ProbeSnapshot $snapshot): array
    {
        $key = $this->hostKeyKey($node);
        $hostKey = $snapshot->get($key);

        if (! is_array($hostKey) || ! $this->hostKeyMissing($node)) {
            return [];
        }

        $pinned = app(SshHostKeyPinner::class)->pin($node->host);
        app(SshHostKeyPinner::class)->persist($node, $pinned);

        return [
            new AdoptResult(
                family: 'node',
                key: $key,
                action: AdoptAction::Updated,
                summary: "Pinned SSH host key for {$node->name}.",
                detail: [
                    'fingerprint' => $pinned->fingerprint,
                    'pin_mode' => $pinned->pinMode,
                ],
            ),
        ];
    }

    public function restore(Node $node, DriftEntry $entry): void
    {
        if ($entry->key === $this->hostKeyKey($node)) {
            if (! $this->hostKeyMissing($node)) {
                throw new RuntimeException('Host key is already pinned; refusing to overwrite it through restore.');
            }

            $pinned = app(SshHostKeyPinner::class)->pin($node->host);
            app(SshHostKeyPinner::class)->persist($node, $pinned);

            return;
        }

        $shell = $this->remoteShell ?? app(RemoteShell::class);

        match ($entry->key) {
            'node.security.sshd_config',
            'node.security.sshd_listen' => app(SshdHardenedInstaller::class)->installFor($node, $shell),
            'node.security.public_ssh_deny' => app(PublicSshDenyInstaller::class)->installFor($node, $shell),
            'node.security.sysctl' => app(SysctlBaselineInstaller::class)->installFor($node, $shell),
            'node.security.runtime_user' => throw new RuntimeException('Runtime user drift is report-only; re-bake or migrate the node.'),
            'node.security.home_perms' => throw new RuntimeException('Home permission drift is report-only; re-bake the node.'),
            default => throw new RuntimeException("Node security cannot restore drift key '{$entry->key}'."),
        };
    }

    private function appliesTo(Node $node): bool
    {
        return $node->isActive()
            && str_starts_with((string) $node->platform, 'ubuntu');
    }

    /**
     * @return list<DriftEntry>
     */
    private function recordDrift(Node $node): array
    {
        $drift = [];

        if ($this->hostKeyMissing($node)) {
            $drift[] = new DriftEntry(
                family: 'node',
                key: $this->hostKeyKey($node),
                kind: DriftKind::Missing,
                summary: "Node {$node->name} is missing pinned SSH host-key material.",
                detail: [
                    'host' => $node->host,
                    'adoptable' => true,
                ],
            );
        }

        if ($this->managedUser($node) === '') {
            $drift[] = new DriftEntry(
                family: 'node',
                key: 'node.security.runtime_user',
                kind: DriftKind::Divergent,
                summary: "Node {$node->name} is missing a steady-state SSH runtime user.",
                detail: [
                    'expected' => 'non-empty managed SSH user',
                    'actual' => $node->user,
                ],
            );
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function firewallDrift(Node $node): array
    {
        $rules = FirewallRule::query()
            ->where('node_id', $node->id)
            ->where('owner', 'node-security')
            ->where('protected', true)
            ->where('action', 'deny')
            ->where('direction', 'incoming')
            ->where('protocol', 'tcp')
            ->where('port', '22')
            ->where('interface', 'public')
            ->pluck('address_family')
            ->all();

        if (in_array('v4', $rules, true) && in_array('v6', $rules, true)) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'node',
                key: 'node.security.public_ssh_deny',
                kind: DriftKind::Missing,
                summary: "Node {$node->name} is missing protected public SSH deny rules.",
                detail: [
                    'expected_families' => ['v4', 'v6'],
                    'actual_families' => $rules,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function remoteDrift(Node $node): array
    {
        try {
            $result = $this->remoteShell?->run($node, $this->postureScript($node), [
                'timeout' => 30,
                'throw' => false,
            ]);
        } catch (Throwable) {
            return [];
        }

        if ($result === null || ! $result->successful()) {
            return [];
        }

        $posture = json_decode(trim($result->stdout), associative: true);

        if (! is_array($posture)) {
            return [];
        }

        $checks = [
            'runtime_user' => 'node.security.runtime_user',
            'sshd_config' => 'node.security.sshd_config',
            'sshd_listen' => 'node.security.sshd_listen',
            'sysctl' => 'node.security.sysctl',
            'home_perms' => 'node.security.home_perms',
        ];

        $drift = [];

        foreach ($checks as $check => $key) {
            if (($posture[$check] ?? null) === true) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: 'node',
                key: $key,
                kind: $key === 'node.security.home_perms' ? DriftKind::Divergent : DriftKind::Missing,
                summary: "Node {$node->name} failed security check {$check}.",
                detail: [
                    'check' => $check,
                    'observed' => $posture[$check] ?? null,
                ],
            );
        }

        return $drift;
    }

    private function postureScript(Node $node): string
    {
        $managedUser = $this->managedUser($node);
        $managedHome = "/home/{$managedUser}";

        return sprintf(<<<'SH_WRAP'
        set -eu

        MANAGED_USER=%s
        MANAGED_HOME=%s
        SSHD_CONFIG='/etc/ssh/sshd_config.d/99-orbit-hardening.conf'

        runtime_user=false
        sshd_config=false
        sshd_listen=true
        sysctl=false
        home_perms=false

        if [ "$MANAGED_USER" != "" ] && id -u "$MANAGED_USER" >/dev/null 2>&1; then
            runtime_user=true
        fi

        if [ -f "$SSHD_CONFIG" ] \
            && grep -Fq 'PasswordAuthentication no' "$SSHD_CONFIG" \
            && grep -Fq "AllowUsers $MANAGED_USER" "$SSHD_CONFIG"; then
            sshd_config=true
        fi

        if [ -f '/etc/sysctl.d/60-orbit.conf' ]; then
            sysctl=true
        fi

        if [ -d "$MANAGED_HOME" ]; then
            home_mode=$(stat -c '%%a' "$MANAGED_HOME" 2>/dev/null || stat -f '%%Lp' "$MANAGED_HOME" 2>/dev/null || printf '')

            case "$home_mode" in
                700|0700)
                    home_perms=true
                    ;;
            esac
        fi

        printf '{"runtime_user":%%s,"sshd_config":%%s,"sshd_listen":%%s,"sysctl":%%s,"home_perms":%%s}' \
            "$runtime_user" \
            "$sshd_config" \
            "$sshd_listen" \
            "$sysctl" \
            "$home_perms"
        SH_WRAP,
            escapeshellarg($managedUser),
            escapeshellarg($managedHome),
        );
    }

    private function managedUser(Node $node): string
    {
        return trim((string) $node->user);
    }

    private function hostKeyMissing(Node $node): bool
    {
        return ! is_string($node->host_key_type)
            || $node->host_key_type === ''
            || ! is_string($node->host_key_public)
            || $node->host_key_public === ''
            || ! is_string($node->host_key_fingerprint)
            || $node->host_key_fingerprint === '';
    }

    private function hostKeyKey(Node $node): string
    {
        return "node.security.host_key.{$node->name}";
    }
}
