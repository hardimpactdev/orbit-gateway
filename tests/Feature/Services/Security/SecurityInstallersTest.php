<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Security\HomeDirectoryLockdownInstaller;
use App\Services\Security\PublicSshDenyInstaller;
use App\Services\Security\SshdHardenedInstaller;
use App\Services\Security\SysctlBaselineInstaller;
use App\Services\Security\UnattendedUpgradesInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

final class RecordingSecurityInstallerShell implements RemoteShell
{
    /** @var list<array{node: string, script: string, options: array<string, mixed>}> */
    public array $runs = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private readonly int $exitCode = 0,
        private array $results = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = [
            'node' => $node->name,
            'script' => $script,
            'options' => $options,
        ];

        if ($this->results !== []) {
            return array_shift($this->results);
        }

        return new RemoteShellResult(
            exitCode: $this->exitCode,
            stdout: '',
            stderr: $this->exitCode === 0 ? '' : 'failed',
            durationMs: 10,
        );
    }
}

describe('security installers', function (): void {
    it('installs the sysctl baseline through remote shell', function (): void {
        $node = Node::factory()->create();
        $shell = new RecordingSecurityInstallerShell;

        $report = app(SysctlBaselineInstaller::class)->installFor($node, $shell);

        expect($report->successful)->toBeTrue()
            ->and($shell->runs[0]['script'])->toContain('/etc/sysctl.d/60-orbit.conf')
            ->and($shell->runs[0]['script'])->toContain('net.ipv4.tcp_syncookies=1')
            ->and($shell->runs[0]['script'])->toContain('kernel.randomize_va_space=2')
            ->and($shell->runs[0]['script'])->toContain('sudo sysctl --system');
    });

    it('locks down the orbit home directory as a bake-time invariant', function (): void {
        $node = Node::factory()->create();
        $shell = new RecordingSecurityInstallerShell;

        $report = app(HomeDirectoryLockdownInstaller::class)->installFor($node, $shell);

        expect($report->successful)->toBeTrue()
            ->and($shell->runs[0]['script'])->toContain('chmod 0700 /home/orbit /home/orbit/.ssh')
            ->and($shell->runs[0]['script'])->toContain('/home/orbit/.config/orbit/logs')
            ->and($shell->runs[0]['script'])->toContain('/home/orbit/.config/orbit/php')
            ->and($shell->runs[0]['script'])->toContain('chmod 0600 /home/orbit/.ssh/authorized_keys');
    });

    it('renders hardened sshd configuration bound to wireguard and loopback', function (): void {
        $node = Node::factory()->create([
            'wireguard_address' => '10.6.0.44',
        ]);
        $shell = new RecordingSecurityInstallerShell;

        $report = app(SshdHardenedInstaller::class)->installFor($node, $shell);

        expect($report->successful)->toBeTrue()
            ->and($shell->runs[0]['script'])->toContain('PermitRootLogin no')
            ->and($shell->runs[0]['script'])->toContain('PasswordAuthentication no')
            ->and($shell->runs[0]['script'])->toContain("MANAGED_USER='orbit'")
            ->and($shell->runs[0]['script'])->toContain('AllowUsers $MANAGED_USER')
            ->and($shell->runs[0]['script'])->toContain('ListenAddress 10.6.0.44')
            ->and($shell->runs[0]['script'])->toContain('ListenAddress 127.0.0.1')
            ->and($shell->runs[0]['script'])->toContain('sudo sshd -t');
    });

    it('renders hardened sshd configuration for a custom managed SSH user', function (): void {
        $node = Node::factory()->create([
            'wireguard_address' => '10.6.0.44',
            'user' => 'nckrtl',
        ]);
        $shell = new RecordingSecurityInstallerShell;

        $report = app(SshdHardenedInstaller::class)->installFor($node, $shell);

        expect($report->successful)->toBeTrue()
            ->and($shell->runs[0]['script'])->toContain("MANAGED_USER='nckrtl'")
            ->and($shell->runs[0]['script'])->toContain('AllowUsers $MANAGED_USER')
            ->and($shell->runs[0]['script'])->not->toContain("MANAGED_USER='orbit'");
    });

    it('installs unattended security upgrades without enabling automatic reboots', function (): void {
        $node = Node::factory()->create();
        $shell = new RecordingSecurityInstallerShell(results: [
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            securityManagedFileProbeResult(exists: false),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            securityManagedFileProbeResult(exists: false),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $report = app(UnattendedUpgradesInstaller::class)->installFor($node, $shell);

        expect($report->successful)->toBeTrue()
            ->and($shell->runs[0]['script'])->toContain('install -y -qq unattended-upgrades')
            ->and($shell->runs[2]['script'])->toContain('/etc/apt/apt.conf.d/20auto-upgrades')
            ->and($shell->runs[4]['script'])->toContain('/etc/apt/apt.conf.d/50unattended-upgrades')
            ->and($report->details['managed_files'])->toHaveCount(2);
    });

    it('declares protected public SSH deny rules and applies them to the public interface', function (): void {
        $node = Node::factory()->create();
        $shell = new RecordingSecurityInstallerShell;

        $report = app(PublicSshDenyInstaller::class)->installFor($node, $shell);

        expect($report->successful)->toBeTrue()
            ->and(FirewallRule::query()->where('node_id', $node->id)->count())->toBe(3)
            ->and(FirewallRule::query()->where('owner', 'node-security')->where('protected', true)->count())->toBe(3)
            ->and(FirewallRule::query()->pluck('address_family')->sort()->values()->all())->toBe(['v4', 'v4', 'v6'])
            ->and($shell->runs[0]['script'])->toContain('install -y -qq ufw')
            ->and($shell->runs[0]['script'])->toContain('UFW is inactive; public SSH deny rules were staged but UFW was not enabled.')
            ->and($shell->runs[0]['script'])->toContain('ip -o link show type wireguard')
            ->and($shell->runs[0]['script'])->toContain('Could not resolve WireGuard interface.')
            ->and($shell->runs[0]['script'])->toContain('ufw allow in on "$WG_IFACE" proto tcp from 10.6.0.0/24')
            ->and($shell->runs[0]['script'])->toContain('ufw deny in on "$PUBLIC_IFACE" proto tcp from 0.0.0.0/0')
            ->and($shell->runs[0]['script'])->toContain('ufw deny in on "$PUBLIC_IFACE" proto tcp from ::/0')
            ->and($shell->runs[0]['script'])->not->toContain('ufw --force enable');
    });
});

function securityManagedFileProbeResult(bool $exists, ?string $hash = null, ?string $mode = null): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'exists' => $exists,
            'hash' => $hash,
            'mode' => $mode,
        ], JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 1,
    );
}
