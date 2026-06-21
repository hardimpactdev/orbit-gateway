<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeStatus;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Nodes\NodeSecurityPostureProbe;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

final class RecordingNodeSecurityShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    public function __construct(private readonly string $stdout = '{}') {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(
            exitCode: 0,
            stdout: $this->stdout,
            stderr: '',
            durationMs: 10,
        );
    }
}

it('does not depend on host PHP for host-lane node security probes', function (): void {
    expect((string) file_get_contents(app_path('Services/Nodes/NodeSecurityPostureProbe.php')))
        ->not->toContain('php -r');
});

it('reports missing host key material and missing runtime users under node security keys', function (): void {
    $node = Node::factory()->create([
        'platform' => 'ubuntu_24-04',
        'status' => NodeStatus::Active,
        'user' => '',
        'host_key_type' => null,
        'host_key_public' => null,
        'host_key_fingerprint' => null,
    ]);

    $drift = app(NodeSecurityPostureProbe::class)->diff($node);

    expect(array_map(fn (DriftEntry $entry): string => $entry->key, $drift))
        ->toContain("node.security.host_key.{$node->name}")
        ->toContain('node.security.runtime_user')
        ->toContain('node.security.public_ssh_deny');
});

it('accepts a custom steady-state SSH runtime user from the node record', function (): void {
    $node = Node::factory()->create([
        'platform' => 'ubuntu_24-04',
        'status' => NodeStatus::Active,
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
        'host_key_fingerprint' => 'SHA256:test',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ]);
    FirewallRule::factory()->create([
        'node_id' => $node->id,
        'address_family' => 'v4',
        'owner' => 'node-security',
        'protected' => true,
        'port' => '22',
        'action' => 'deny',
        'direction' => 'incoming',
        'interface' => 'public',
    ]);
    FirewallRule::factory()->create([
        'node_id' => $node->id,
        'address_family' => 'v6',
        'owner' => 'node-security',
        'protected' => true,
        'port' => '22',
        'action' => 'deny',
        'direction' => 'incoming',
        'interface' => 'public',
    ]);
    $shell = new RecordingNodeSecurityShell(json_encode([
        'runtime_user' => true,
        'sshd_config' => true,
        'sshd_listen' => true,
        'sysctl' => true,
        'home_perms' => true,
    ], JSON_THROW_ON_ERROR));

    $drift = (new NodeSecurityPostureProbe($shell))->diff($node);

    expect($drift)->toBe([])
        ->and($shell->scripts[0])->toStartWith('set -eu')
        ->and($shell->scripts[0])->not->toContain('php -r')
        ->and($shell->scripts[0])->toContain("MANAGED_USER='nckrtl'")
        ->and($shell->scripts[0])->toContain('id -u "$MANAGED_USER"')
        ->and($shell->scripts[0])->toContain('AllowUsers $MANAGED_USER')
        ->and($shell->scripts[0])->toContain('printf \'{"runtime_user":%s')
        ->and($shell->scripts[0])->toContain('/home/nckrtl');
});

it('reports remote node security drift from the posture script', function (): void {
    $node = Node::factory()->create([
        'platform' => 'ubuntu_24-04',
        'status' => NodeStatus::Active,
        'wireguard_address' => '10.6.0.5',
        'user' => 'orbit',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
        'host_key_fingerprint' => 'SHA256:test',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ]);
    FirewallRule::factory()->create([
        'node_id' => $node->id,
        'address_family' => 'v4',
        'owner' => 'node-security',
        'protected' => true,
        'port' => '22',
        'action' => 'deny',
        'direction' => 'incoming',
        'interface' => 'public',
    ]);
    FirewallRule::factory()->create([
        'node_id' => $node->id,
        'address_family' => 'v6',
        'owner' => 'node-security',
        'protected' => true,
        'port' => '22',
        'action' => 'deny',
        'direction' => 'incoming',
        'interface' => 'public',
    ]);
    $shell = new RecordingNodeSecurityShell(json_encode([
        'runtime_user' => true,
        'sshd_config' => false,
        'sshd_listen' => true,
        'unattended_upgrades' => false,
        'sysctl' => true,
        'home_perms' => false,
    ], JSON_THROW_ON_ERROR));

    $drift = (new NodeSecurityPostureProbe($shell))->diff($node);

    expect(array_map(fn (DriftEntry $entry): string => $entry->key, $drift))
        ->toBe([
            'node.security.sshd_config',
            'node.security.home_perms',
        ]);
});

it('can adopt the first host key pin for legacy nodes', function (): void {
    $publicKey = 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests';
    $node = Node::factory()->create([
        'host' => '203.0.113.44',
        'platform' => 'ubuntu_24-04',
        'status' => NodeStatus::Active,
        'host_key_type' => null,
        'host_key_public' => null,
        'host_key_fingerprint' => null,
    ]);
    Process::fake([
        'ssh-keyscan*' => Process::result(output: "203.0.113.44 ssh-ed25519 {$publicKey}\n"),
    ]);
    Process::preventStrayProcesses();

    $probe = app(NodeSecurityPostureProbe::class);
    $results = $probe->adopt($node, $probe->snapshotForAdopt($node, includeHostKey: true));

    expect($results[0]->action->value)->toBe('updated')
        ->and($node->refresh()->host_key_type)->toBe('ssh-ed25519')
        ->and($node->host_key_fingerprint)->toBe(SshHostKeyPinner::fingerprintForPublicKey($publicKey));
});
