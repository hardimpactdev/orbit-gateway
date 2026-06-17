<?php

declare(strict_types=1);

use App\Actions\Nodes\ReenactNodeArtifacts;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rotates wireguard endpoints when gateway endpoint changes', function (): void {
    $shell = new ReenactNodeArtifactsRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'host' => '192.0.2.10',
        'wireguard_address' => '10.6.0.10',
        'gateway_endpoint' => '10.3.0.2',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAATEST',
        'host_key_fingerprint' => 'SHA256:test',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-prod',
        'status' => 'active',
    ]);

    $warnings = app(ReenactNodeArtifacts::class)->handle($node, ['gateway_endpoint']);

    expect($warnings)->toBe([])
        ->and($shell->scripts)->toHaveCount(1)
        ->and($shell->nodes)->toBe(['app-1'])
        ->and($shell->scripts[0])->toContain("endpoint='10.3.0.2:51820'")
        ->and($shell->scripts[0])->toContain('/etc/wireguard/wg-orbit.conf')
        ->and($shell->scripts[0])->toContain('/etc/wireguard/wg0.conf')
        ->and($shell->scripts[0])->toContain('before-gateway-endpoint-')
        ->and($shell->scripts[0])->toContain('Endpoint = ${endpoint}')
        ->and($shell->scripts[0])->toContain('wg set "$iface" peer "$peer" endpoint "$endpoint"');
});

it('returns a warning when wireguard endpoint rotation fails', function (): void {
    $shell = new ReenactNodeArtifactsRecordingShell(
        result: new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'missing wireguard config', durationMs: 1),
    );
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'wireguard_address' => '10.6.0.10',
        'gateway_endpoint' => '10.3.0.2',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAATEST',
        'host_key_fingerprint' => 'SHA256:test',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-prod',
        'status' => 'active',
    ]);

    $warnings = app(ReenactNodeArtifacts::class)->handle($node, ['gateway_endpoint']);

    expect($warnings)->toBe([[
        'code' => 'node.artifact_enactment_failed',
        'message' => 'Node artifact re-enactment failed after intent update.',
        'family' => 'node',
        'next_command' => 'doctor --family=node --restore',
    ]]);
});

it('does not rotate the local gateway node when its advertised endpoint metadata changes', function (): void {
    $shell = new ReenactNodeArtifactsRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create([
        'name' => 'gateway',
        'wireguard_address' => '10.6.0.2',
        'gateway_endpoint' => '188.245.156.201',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    $warnings = app(ReenactNodeArtifacts::class)->handle($node, ['gateway_endpoint']);

    expect($warnings)->toBe([])
        ->and($shell->scripts)->toBe([]);
});

final class ReenactNodeArtifactsRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $nodes = [];

    /** @var list<string> */
    public array $scripts = [];

    public function __construct(
        private RemoteShellResult $result = new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node->name;
        $this->scripts[] = $script;

        return $this->result;
    }
}
