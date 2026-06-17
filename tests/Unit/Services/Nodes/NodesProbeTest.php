<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nodes;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\AdoptAction;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\WireGuardPeer;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\NodesProbe;
use App\Services\Platform\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bindDevelopmentDnsMappingTestDoubles('nodes-probe-dns');
    $this->probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([]));
});

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

function nodesProbeDevelopmentDnsPath(?string $file = null): string
{
    $configDir = app(DevelopmentDnsMappingEnactor::class)->configDir();

    return $file === null ? $configDir : "{$configDir}/{$file}";
}

function assignNodesProbeAppHostRole(Node $node, array $settings = ['tld' => 'test']): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => $settings,
    ]);
}

function assignNodesProbeGatewayRole(Node $node): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);
}

describe('interface contract', function (): void {
    it('has key and label', function (): void {
        expect($this->probe->key())->toBe('node');
        expect($this->probe->label())->toBe('Node');
    });

    it('returns empty snapshot from introspect', function (): void {
        $node = new Node(['name' => 'test']);
        $snapshot = $this->probe->introspect($node);

        expect($snapshot->isEmpty())->toBeTrue();
    });

    it('declares reconcile and adopt support', function (): void {
        expect($this->probe->canReconcile())->toBeTrue();
        expect($this->probe->canAdopt())->toBeTrue();
    });
});

describe('record completeness', function (): void {
    it('detects incomplete records', function (): void {
        $id = DB::table('nodes')->insertGetId([
            'name' => 'incomplete',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $node = Node::find($id);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));

        expect($drift)->toHaveCount(1);
        expect($drift[0]->key)->toBe('node.record_incomplete');
        expect($drift[0]->kind)->toBe(DriftKind::Missing);
    });

    it('passes complete records', function (): void {
        $node = Node::create([
            'name' => 'complete',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $recordIncomplete = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.record_incomplete');

        expect($recordIncomplete)->toHaveCount(0);
    });

    it('does not run dependent app live checks when required transport metadata is missing', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'should not run', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'incomplete-prod',
            'host' => '46.225.89.66',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
        ]);
        assignNodesProbeAppHostRole($node, []);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $keys = array_map(fn (DriftEntry $entry): string => $entry->key, $drift);

        expect($keys)->toContain('node.record_incomplete')
            ->and($keys)->not->toContain('node.ssh_unreachable')
            ->and($keys)->not->toContain('node.runtime_missing')
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('does not synthesize missing role drift for unassigned nodes', function (): void {
        $node = Node::create([
            'name' => 'app-no-env',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $missingRoleAssignment = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_assignment_missing');

        expect($missingRoleAssignment)->toHaveCount(0);
    });

    it('does not require environment for non-app nodes', function (): void {
        $node = Node::create([
            'name' => 'gateway-no-env',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $recordIncomplete = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.record_incomplete');

        expect($recordIncomplete)->toHaveCount(0);
    });
});

describe('agent IDE default', function (): void {
    it('passes when no config is set', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $agentIde = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.agent_ide_default_invalid');

        expect($agentIde)->toHaveCount(0);
    });

    it('detects unsupported adapter', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $node->forceFill(['agent_ide_config' => ['adapter' => 'unsupported']]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $agentIde = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.agent_ide_default_invalid');

        expect($agentIde)->toHaveCount(1);
        expect($agentIde[array_key_first($agentIde)]->kind)->toBe(DriftKind::Divergent);
    });

    it('passes for supported adapter', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $node->forceFill(['agent_ide_config' => ['adapter' => 'opencode']]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $agentIde = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.agent_ide_default_invalid');

        expect($agentIde)->toHaveCount(0);
    });
});

describe('access grants', function (): void {
    it('passes when no grants exist', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $access = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_grant_invalid');

        expect($access)->toHaveCount(0);
    });

    it('detects stale consuming grants', function (): void {
        $consumer = Node::create([
            'name' => 'consumer',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = Node::create([
            'name' => 'serving',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
        ]);

        $serving->update(['status' => 'decommissioned']);

        $drift = $this->probe->diff($consumer, new ProbeSnapshot([]));
        $access = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_grant_invalid');

        expect($access)->toHaveCount(1);
    });

    it('detects stale serving grants', function (): void {
        $consumer = Node::create([
            'name' => 'consumer',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = Node::create([
            'name' => 'serving',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
        ]);

        $consumer->update(['status' => 'decommissioned']);

        $drift = $this->probe->diff($serving, new ProbeSnapshot([]));
        $access = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_grant_invalid');

        expect($access)->toHaveCount(1);
    });
});

describe('external service stubs', function (): void {
    it('detects missing WireGuard peer material for active non-gateway nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $wireguard = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.wireguard_peer_missing'));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->kind)->toBe(DriftKind::Missing);
    });

    it('accepts matching WireGuard peer material', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $wireguard = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.wireguard'));

        expect($wireguard)->toHaveCount(0);
    });

    it('detects WireGuard peer address mismatches', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.8/32',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $wireguard = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.wireguard_address_mismatch'));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->kind)->toBe(DriftKind::Divergent);
    });

    it('detects WireGuard peers attached to non-active nodes as extra', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'decommissioned',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $wireguard = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.wireguard_peer_extra'));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->kind)->toBe(DriftKind::Extra);
    });

    it('returns empty for platform reality checks on remote nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $platform = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.platform'));

        expect($platform)->toHaveCount(0);
    });

    it('detects local platform record mismatches', function (): void {

        $probe = new NodesProbe(new class extends PlatformDetector
        {
            public function detectLocal(): string
            {
                return 'macos_15-4';
            }
        });

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14-0',
            'wireguard_address' => '10.6.0.2',
        ]);
        assignNodesProbeGatewayRole($node);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $platform = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.platform_record_mismatch'));

        expect($platform)->toHaveCount(1);
        expect($platform[0]->kind)->toBe(DriftKind::Divergent);
        expect($platform[0]->detail)->toBe([
            'recorded' => 'macos_14-0',
            'observed' => 'macos_15-4',
        ]);
    });

    it('detects unsupported local platform detection', function (): void {

        $probe = new NodesProbe(new class extends PlatformDetector
        {
            public function detectLocal(): string
            {
                throw new RuntimeException('Unsupported platform family: Solaris');
            }
        });

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'solaris_11',
            'wireguard_address' => '10.6.0.2',
        ]);
        assignNodesProbeGatewayRole($node);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $platform = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.platform_unsupported'));

        expect($platform)->toHaveCount(1);
        expect($platform[0]->kind)->toBe(DriftKind::Unverifiable);
        expect($platform[0]->summary)->toBe('Could not detect local platform for test: Unsupported platform family: Solaris');
    });

    it('accepts reachable app nodes over SSH', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.5/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $ssh = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.ssh_unreachable');

        expect($ssh)->toHaveCount(0);
        expect(array_slice($remoteShell->scripts, 0, 2))->toBe([
            'true',
            'command -v systemctl >/dev/null 2>&1 && systemctl --version >/dev/null 2>&1',
        ]);
        expect($remoteShell->scripts)->toHaveCount(3);
        expect($remoteShell->scripts[2])->toContain('"runtime_user"');
        expect($remoteShell->options[0]['timeout'])->toBe(10);
    });

    it('detects unreachable app nodes over SSH', function (): void {
        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'connection refused', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.5/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $ssh = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.ssh_unreachable'));

        expect($ssh)->toHaveCount(1);
        expect($ssh[0]->kind)->toBe(DriftKind::Unverifiable);
        expect($ssh[0]->detail)->toBe([
            'exit_code' => 255,
            'output' => 'connection refused',
        ]);
    });

    it('skips SSH reachability for non-app nodes', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'should not run', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'gateway',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
        ]);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $ssh = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.ssh_unreachable');

        expect($ssh)->toHaveCount(0);
        expect($remoteShell->scripts)->toHaveCount(1);
        expect($remoteShell->scripts[0])->toContain('"runtime_user"');
    });

    it('returns empty for gateway service checks', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $runtime = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.gateway_runtime_unready');

        expect($runtime)->toHaveCount(0);
    });

    it('accepts available app runtime backend', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.5/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $runtime = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.runtime_missing');

        expect($runtime)->toHaveCount(0);
        expect(array_slice($remoteShell->scripts, 0, 2))->toBe([
            'true',
            'command -v systemctl >/dev/null 2>&1 && systemctl --version >/dev/null 2>&1',
        ]);
        expect($remoteShell->scripts)->toHaveCount(3);
        expect($remoteShell->scripts[2])->toContain('"runtime_user"');
    });

    it('detects missing app runtime backend', function (): void {
        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'missing systemctl', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);
        WireGuardPeer::factory()->create(['node_id' => $node->id, 'allowed_ips' => '10.6.0.5/32']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $runtime = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.runtime_missing'));

        expect($runtime)->toHaveCount(1);
        expect($runtime[0]->kind)->toBe(DriftKind::Unverifiable);
        expect($runtime[0]->detail)->toBe([
            'exit_code' => 127,
            'output' => 'missing systemctl',
        ]);
    });

    it('skips app runtime checks for non-app nodes', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'should not run', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'gateway',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.1',
        ]);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $runtime = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.runtime_missing');

        expect($runtime)->toHaveCount(0);
        expect($remoteShell->scripts)->toHaveCount(1);
        expect($remoteShell->scripts[0])->toContain('"runtime_user"');
    });

    it('detects missing development TLD for development app nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => [],
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $tld = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_settings_invalid'));

        expect($tld)->toHaveCount(1);
        expect($tld[0]->kind)->toBe(DriftKind::Divergent);
    });

    it('accepts configured development TLD for development app nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        File::ensureDirectoryExists(nodesProbeDevelopmentDnsPath());
        File::put(nodesProbeDevelopmentDnsPath('test.conf'), implode("\n", [
            '# orbit-managed=node-development-dns',
            '# node=test',
            '# bind-scope=orbit_network',
            'address=/test/10.6.0.5',
            '',
        ]));

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $tld = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.role_'));

        expect($tld)->toHaveCount(0);
    });

    it('detects missing gateway development dns mapping for development app nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $mapping = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_baseline_mismatch'));

        expect($mapping)->toHaveCount(1);
        expect($mapping[0]->kind)->toBe(DriftKind::Missing);
    });

    it('detects wrong gateway development dns mapping targets', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        File::ensureDirectoryExists(nodesProbeDevelopmentDnsPath());
        File::put(nodesProbeDevelopmentDnsPath('test.conf'), implode("\n", [
            '# orbit-managed=node-development-dns',
            '# node=test',
            '# bind-scope=orbit_network',
            'address=/test/10.6.0.99',
            '',
        ]));

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $mapping = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_baseline_mismatch'));

        expect($mapping)->toHaveCount(1);
        expect($mapping[0]->kind)->toBe(DriftKind::Divergent);
    });

    it('detects public gateway development dns resolver exposure', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        File::ensureDirectoryExists(nodesProbeDevelopmentDnsPath());
        File::put(nodesProbeDevelopmentDnsPath('test.conf'), implode("\n", [
            '# orbit-managed=node-development-dns',
            '# node=test',
            '# bind-scope=public',
            'address=/test/10.6.0.5',
            '',
        ]));

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $exposure = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_baseline_mismatch'));

        expect($exposure)->toHaveCount(1);
        expect($exposure[0]->kind)->toBe(DriftKind::Divergent);
    });

    it('does not require development TLD for production app nodes', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $tld = array_filter($drift, fn (DriftEntry $e): bool => str_starts_with($e->key, 'node.development'));

        expect($tld)->toHaveCount(0);
    });

    it('returns empty for CLI PHP default checks', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $php = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.cli_php_default_mismatch');

        expect($php)->toHaveCount(0);
    });
});

describe('reconciliation', function (): void {
    it('throws for unsupported drift keys', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $entry = new DriftEntry(
            family: 'nodes',
            key: 'node.record_incomplete',
            kind: DriftKind::Missing,
            summary: 'test',
        );

        expect(fn () => $this->probe->reconcile($node, $entry))
            ->toThrow(RuntimeException::class, "NodesProbe cannot reconcile drift key 'node.record_incomplete'.");
    });

    it('does not throw for supported drift keys', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $supportedKeys = [
            'node.wireguard_peer_missing',
            'node.wireguard_address_mismatch',
            'node.gateway_runtime_unready',
            'node.runtime_missing',
            'node.access_grant_invalid',
            'node.role_convergence_failed',
            'node.role_baseline_mismatch',
        ];

        foreach ($supportedKeys as $key) {
            $entry = new DriftEntry(
                family: 'nodes',
                key: $key,
                kind: DriftKind::Divergent,
                summary: 'test',
            );

            expect(fn () => $this->probe->reconcile($node, $entry))->not->toThrow(RuntimeException::class);
        }
    });

    it('removes stale access grants on reconcile', function (): void {
        $consumer = Node::create([
            'name' => 'consumer',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = Node::create([
            'name' => 'serving',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
        ]);

        $serving->update(['status' => 'decommissioned']);

        $entry = new DriftEntry(
            family: 'nodes',
            key: 'node.access_grant_invalid',
            kind: DriftKind::Divergent,
            summary: 'test',
        );

        $this->probe->reconcile($consumer, $entry);

        expect(NodeAccess::query()->count())->toBe(0);
    });

    it('repairs gateway development dns mapping drift on reconcile', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        $node->roleAssignments()->create([
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        File::ensureDirectoryExists(nodesProbeDevelopmentDnsPath());
        File::put(nodesProbeDevelopmentDnsPath('test.conf'), implode("\n", [
            '# orbit-managed=node-development-dns',
            '# node=test',
            '# bind-scope=public',
            'address=/test/10.6.0.99',
            '',
        ]));

        $entry = new DriftEntry(
            family: 'nodes',
            key: 'node.role_baseline_mismatch',
            kind: DriftKind::Divergent,
            summary: 'test',
            detail: [
                'role' => 'app-dev',
                'tld' => 'test',
            ],
        );

        $this->probe->reconcile($node, $entry);

        expect(File::get(nodesProbeDevelopmentDnsPath('test.conf')))
            ->toContain('# bind-scope=orbit_network')
            ->toContain('address=/test/10.6.0.5');
    });
});

describe('adoption', function (): void {
    it('returns empty adopt snapshot when no adoptable node reality is detected', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $snapshot = $this->probe->snapshotForAdopt($node);

        expect($snapshot->isEmpty())->toBeTrue();
    });

    it('snapshots local platform record mismatches for adopt', function (): void {

        $probe = new NodesProbe(new class extends PlatformDetector
        {
            public function detectLocal(): string
            {
                return 'macos_15-4';
            }
        });

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14-0',
            'wireguard_address' => '10.6.0.2',
        ]);
        assignNodesProbeGatewayRole($node);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.platform_record_mismatch'))->toBe([
            'recorded' => 'macos_14-0',
            'observed' => 'macos_15-4',
        ]);
    });

    it('snapshots unambiguous WireGuard address mismatches for adopt', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.8/32',
        ]);

        $snapshot = $this->probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_address_mismatch'))->toBe([
            'recorded' => '10.6.0.5',
            'observed' => '10.6.0.8',
            'allowed_ips' => '10.6.0.8/32',
        ]);
    });

    it('does not snapshot ambiguous WireGuard address mismatches for adopt', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.8/32, fd00::8/128',
        ]);

        $snapshot = $this->probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_address_mismatch'))->toBeNull();
    });

    it('snapshots compatible live WireGuard peer extras for adopt', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "peer-public-key\t10.6.0.8/32\n"),
        ]);

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'decommissioned',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'public_key' => 'peer-public-key',
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $snapshot = $this->probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_extra'))->toBe([
            'recorded_status' => 'decommissioned',
            'public_key' => 'peer-public-key',
            'observed' => '10.6.0.8',
            'allowed_ips' => ['10.6.0.8/32'],
        ]);
    });

    it('does not snapshot unproven live WireGuard peer extras for adopt', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "different-public-key\t10.6.0.8/32\n"),
        ]);

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'decommissioned',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'public_key' => 'peer-public-key',
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $snapshot = $this->probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_extra'))->toBeNull();
    });

    it('snapshots compatible live WireGuard peer missing for adopt', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);

        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: nodeIdentityArtifactPayload(), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);
        assignNodesProbeAppHostRole($node);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_missing'))->toBe([
            'public_key' => 'app-public-key',
            'observed' => '10.6.0.8',
            'allowed_ips' => ['10.6.0.8/32'],
            'artifact' => [
                'name' => 'test',
                'role' => 'app-dev',
                'local_role' => 'app-dev',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'wireguard_address' => '10.6.0.8',
                'registry_public_key' => null,
                'interface_public_key' => 'app-public-key',
            ],
        ]);
    });

    it('does not snapshot app host adoption when identity artifact role disagrees with assignments', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);

        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: nodeIdentityArtifactPayload(['role' => 'unknown', 'local_role' => 'unknown']), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);
        assignNodesProbeAppHostRole($node);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_missing'))->toBeNull()
            ->and($snapshot->get('node.runtime_missing'))->toMatchArray([
                'available' => true,
                'exit_code' => 0,
            ]);
    });

    it('does not snapshot hosted app adoption for unassigned nodes', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_missing'))->toBeNull()
            ->and($snapshot->get('node.runtime_missing'))->toBeNull()
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('does not snapshot unproven live WireGuard peer missing for adopt', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);

        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: nodeIdentityArtifactPayload(['name' => 'other']), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);
        assignNodesProbeAppHostRole($node);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.wireguard_peer_missing'))->toBeNull();
    });

    it('snapshots available app runtime readiness for adopt', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "systemd 255\n", stderr: '', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.runtime_missing'))->toBe([
            'available' => true,
            'exit_code' => 0,
            'output' => 'systemd 255',
        ]);
        expect($remoteShell->scripts)->toBe([
            'command -v systemctl >/dev/null 2>&1 && systemctl --version >/dev/null 2>&1',
        ]);
    });

    it('snapshots unavailable app runtime readiness for adopt', function (): void {
        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'command not found: systemctl', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $snapshot = $probe->snapshotForAdopt($node);

        expect($snapshot->get('node.runtime_missing'))->toBe([
            'available' => false,
            'exit_code' => 127,
            'output' => 'command not found: systemctl',
        ]);
    });

    it('returns skipped results for adoptable keys', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $results = $this->probe->adopt($node, new ProbeSnapshot([]));

        expect($results)->toHaveCount(5);

        $keys = array_map(fn ($r) => $r->key, $results);
        expect($keys)->toContain('node.wireguard_peer_missing');
        expect($keys)->toContain('node.wireguard_peer_extra');
        expect($keys)->toContain('node.wireguard_address_mismatch');
        expect($keys)->toContain('node.runtime_missing');
        expect($keys)->toContain('node.platform_record_mismatch');

        foreach ($results as $result) {
            expect($result->action)->toBe(AdoptAction::Skipped);
        }
    });

    it('adopts local platform record mismatches', function (): void {

        $probe = new NodesProbe(new class extends PlatformDetector
        {
            public function detectLocal(): string
            {
                return 'macos_15-4';
            }
        });

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14-0',
            'wireguard_address' => '10.6.0.2',
        ]);
        assignNodesProbeGatewayRole($node);

        $results = $probe->adopt($node, $probe->snapshotForAdopt($node));
        $platform = array_values(array_filter($results, fn ($result): bool => $result->key === 'node.platform_record_mismatch'));

        expect($platform)->toHaveCount(1);
        expect($platform[0]->action)->toBe(AdoptAction::Updated);
        expect($platform[0]->detail)->toBe([
            'recorded' => 'macos_14-0',
            'observed' => 'macos_15-4',
        ]);
        expect($node->refresh()->platform)->toBe('macos_15-4');
    });

    it('adopts unambiguous WireGuard address mismatches', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.8/32',
        ]);

        $results = $this->probe->adopt($node, $this->probe->snapshotForAdopt($node));
        $wireguard = array_values(array_filter($results, fn ($result): bool => $result->key === 'node.wireguard_address_mismatch'));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->action)->toBe(AdoptAction::Updated);
        expect($wireguard[0]->detail)->toBe([
            'recorded' => '10.6.0.5',
            'observed' => '10.6.0.8',
            'allowed_ips' => '10.6.0.8/32',
        ]);
        expect($node->refresh()->wireguard_address)->toBe('10.6.0.8');
    });

    it('adopts compatible live WireGuard peer extras', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "peer-public-key\t10.6.0.8/32\n"),
        ]);

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'decommissioned',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'public_key' => 'peer-public-key',
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $results = $this->probe->adopt($node, $this->probe->snapshotForAdopt($node));
        $wireguard = array_values(array_filter($results, fn ($result): bool => $result->key === 'node.wireguard_peer_extra'));

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->action)->toBe(AdoptAction::Updated);
        expect($wireguard[0]->detail)->toBe([
            'recorded_status' => 'decommissioned',
            'public_key' => 'peer-public-key',
            'observed' => '10.6.0.8',
            'allowed_ips' => ['10.6.0.8/32'],
        ]);
        expect($node->refresh()->status)->toBe(NodeStatus::Active);
        expect($node->wireguard_address)->toBe('10.6.0.8');
    });

    it('adopts compatible live WireGuard peer missing', function (): void {
        Process::preventStrayProcesses();
        Process::fake([
            'sudo wg show wg-orbit allowed-ips' => Process::result(output: "app-public-key\t10.6.0.8/32\n"),
        ]);

        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: nodeIdentityArtifactPayload(), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.8',
        ]);
        assignNodesProbeAppHostRole($node);

        $results = $probe->adopt($node, $probe->snapshotForAdopt($node));
        $wireguard = array_values(array_filter($results, fn ($result): bool => $result->key === 'node.wireguard_peer_missing'));
        $peer = WireGuardPeer::query()->where('node_id', $node->id)->first();

        expect($wireguard)->toHaveCount(1);
        expect($wireguard[0]->action)->toBe(AdoptAction::Updated);
        expect($peer)->toBeInstanceOf(WireGuardPeer::class);
        expect($peer->public_key)->toBe('app-public-key');
        expect($peer->private_key)->toBe('');
        expect($peer->allowed_ips)->toBe('10.6.0.8/32');
    });

    it('adopts available app runtime readiness', function (): void {
        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $results = $probe->adopt($node, $probe->snapshotForAdopt($node));
        $runtime = array_values(array_filter($results, fn ($result): bool => $result->key === 'node.runtime_missing'));

        expect($runtime)->toHaveCount(1);
        expect($runtime[0]->action)->toBe(AdoptAction::Updated);
        expect($runtime[0]->detail)->toBe([
            'available' => true,
            'exit_code' => 0,
            'output' => 'systemd OK',
        ]);
    });

    it('conflicts unavailable app runtime readiness during adopt', function (): void {
        $probe = new NodesProbe(remoteShell: new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'missing systemctl', durationMs: 1),
        ]));

        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);
        assignNodesProbeAppHostRole($node);

        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);

        $results = $probe->adopt($node, $probe->snapshotForAdopt($node));
        $runtime = array_values(array_filter($results, fn ($result): bool => $result->key === 'node.runtime_missing'));

        expect($runtime)->toHaveCount(1);
        expect($runtime[0]->action)->toBe(AdoptAction::Conflict);
        expect($runtime[0]->detail)->toBe([
            'available' => false,
            'exit_code' => 127,
            'output' => 'missing systemctl',
        ]);
    });
});

describe('public IP metadata exclusion', function (): void {
    it('does not detect public IP drift', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
            'public_ipv4' => '1.2.3.4',
            'public_ipv6' => null,
        ]);
        markNodeSecurityBaselineClean($node);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $ipIssues = array_filter($drift, fn (DriftEntry $e): bool => str_contains($e->key, 'public'));

        expect($ipIssues)->toHaveCount(0);
    });
});

describe('agent role baseline', function (): void {
    it('detects missing agent DNS mapping', function (): void {
        $node = Node::create([
            'name' => 'agent-1',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
            'tld' => 'agent',
        ]);
        $node->roleAssignments()->create([
            'role' => 'agent',
            'status' => 'active',
            'settings' => ['tld' => 'agent'],
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $baseline = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_baseline_mismatch' && ($e->detail['component'] ?? null) === 'dns_mapping'));

        expect($baseline)->toHaveCount(1);
        expect($baseline[0]->kind)->toBe(DriftKind::Missing);
        expect($baseline[0]->detail['component'] ?? null)->toBe('dns_mapping');
    });

    it('detects missing caddy baseline tool for agent nodes', function (): void {
        $node = Node::create([
            'name' => 'agent-1',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
            'tld' => 'agent',
        ]);
        $node->roleAssignments()->create([
            'role' => 'agent',
            'status' => 'active',
            'settings' => ['tld' => 'agent'],
        ]);
        File::ensureDirectoryExists(nodesProbeDevelopmentDnsPath());
        File::put(nodesProbeDevelopmentDnsPath('agent.conf'), implode("\n", [
            '# orbit-managed=node-development-dns',
            '# node=agent-1',
            '# bind-scope=orbit_network',
            'address=/agent/10.6.0.5',
            '',
        ]));

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $baseline = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_baseline_mismatch' && ($e->detail['tool'] ?? null) === 'caddy'));

        expect($baseline)->toHaveCount(1);
        expect($baseline[0]->kind)->toBe(DriftKind::Missing);
    });

    it('detects missing agent runtime user for agent nodes', function (): void {
        $remoteShell = new NodesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'no such user', durationMs: 1),
        ]);
        $probe = new NodesProbe(remoteShell: $remoteShell);

        $node = Node::create([
            'name' => 'agent-1',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
            'tld' => 'agent',
        ]);
        $node->roleAssignments()->create([
            'role' => 'agent',
            'status' => 'active',
            'settings' => ['tld' => 'agent'],
        ]);
        File::ensureDirectoryExists(nodesProbeDevelopmentDnsPath());
        File::put(nodesProbeDevelopmentDnsPath('agent.conf'), implode("\n", [
            '# orbit-managed=node-development-dns',
            '# node=agent-1',
            '# bind-scope=orbit_network',
            'address=/agent/10.6.0.5',
            '',
        ]));
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'caddy']);

        $drift = $probe->diff($node, new ProbeSnapshot([]));
        $baseline = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_baseline_mismatch' && ($e->detail['component'] ?? null) === 'agent_user'));

        expect($baseline)->toHaveCount(1);
        expect($baseline[0]->kind)->toBe(DriftKind::Missing);
    });

    it('passes when agent DNS mapping is correct', function (): void {
        $node = Node::create([
            'name' => 'agent-1',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
            'tld' => 'agent',
        ]);
        $node->roleAssignments()->create([
            'role' => 'agent',
            'status' => 'active',
            'settings' => ['tld' => 'agent'],
        ]);
        File::ensureDirectoryExists(nodesProbeDevelopmentDnsPath());
        File::put(nodesProbeDevelopmentDnsPath('agent.conf'), implode("\n", [
            '# orbit-managed=node-development-dns',
            '# node=agent-1',
            '# bind-scope=orbit_network',
            'address=/agent/10.6.0.5',
            '',
        ]));
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'caddy']);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $baseline = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.role_baseline_mismatch');

        expect($baseline)->toHaveCount(0);
    });
});

describe('access permission validity', function (): void {
    it('passes when no grants exist', function (): void {
        $node = Node::create([
            'name' => 'test',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        $drift = $this->probe->diff($node, new ProbeSnapshot([]));
        $permission = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_permission_invalid');

        expect($permission)->toHaveCount(0);
    });

    it('passes normalized permissions on grants', function (): void {
        $consumer = Node::create([
            'name' => 'consumer',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = Node::create([
            'name' => 'serving',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
            'permissions' => ['doctor:verify', 'node:read', 'tool:read', 'tool:update:agent-tools'],
        ]);

        $drift = $this->probe->diff($consumer, new ProbeSnapshot([]));
        $permission = array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_permission_invalid');

        expect($permission)->toHaveCount(0);
    });

    it('detects unknown permissions on grants', function (): void {
        $consumer = Node::create([
            'name' => 'consumer',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = Node::create([
            'name' => 'serving',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
            'permissions' => ['tool:read', 'tool:nope'],
        ]);

        $drift = $this->probe->diff($consumer, new ProbeSnapshot([]));
        $permission = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_permission_invalid'));

        expect($permission)->toHaveCount(1);
        expect($permission[0]->kind)->toBe(DriftKind::Divergent);
        expect($permission[0]->detail['unknown_permissions'] ?? null)->toBe(['tool:nope']);
    });

    it('detects redundant permissions on grants', function (): void {
        $consumer = Node::create([
            'name' => 'consumer',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'macos_14',
            'wireguard_address' => '10.6.0.2',
        ]);

        $serving = Node::create([
            'name' => 'serving',
            'host' => '10.0.0.1',
            'orbit_path' => '/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.5',
        ]);

        NodeAccess::create([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
            'permissions' => ['tool:read', 'tool:list'],
        ]);

        $drift = $this->probe->diff($consumer, new ProbeSnapshot([]));
        $permission = array_values(array_filter($drift, fn (DriftEntry $e): bool => $e->key === 'node.access_permission_invalid'));

        expect($permission)->toHaveCount(1);
        expect($permission[0]->kind)->toBe(DriftKind::Divergent);
        expect($permission[0]->detail['stored_permissions'] ?? null)->toBe(['tool:read', 'tool:list']);
    });
});

/**
 * @param  array<string, mixed>  $overrides
 */
function nodeIdentityArtifactPayload(array $overrides = []): string
{
    return json_encode(array_merge([
        'name' => 'test',
        'role' => 'app-dev',
        'local_role' => 'app-dev',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.8',
        'registry_public_key' => null,
        'interface_public_key' => 'app-public-key',
    ], $overrides), JSON_THROW_ON_ERROR);
}

final class NodesProbeRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
