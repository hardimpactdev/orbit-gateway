<?php

declare(strict_types=1);

use App\Data\Security\PinnedHostKey;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Security\SshHostKeyPinner;
use App\Services\WebSockets\WebSocketRoleBaselineTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as m;

uses(RefreshDatabase::class);

afterEach(function (): void {
    m::close();
});

describe('orbit:internal:bake-websocket-node', function (): void {
    beforeEach(function (): void {
        $this->hostKeyPinner = new class
        {
            /** @var list<array{host: string, expected: ?string}> */
            public array $calls = [];

            public function pin(string $host, ?string $expectedFingerprint = null): PinnedHostKey
            {
                $this->calls[] = ['host' => $host, 'expected' => $expectedFingerprint];

                return new PinnedHostKey(
                    host: $host,
                    type: 'ssh-ed25519',
                    publicKey: 'AAAAC3NzaC1lZDI1NTE5AAAAIBakeWebSocketNodeHostKey',
                    fingerprint: 'SHA256:bake-websocket-node-host-key',
                    pinMode: 'tofu',
                );
            }
        };

        app()->instance(SshHostKeyPinner::class, $this->hostKeyPinner);
    });

    it('adds the websocket role to an existing app-dev Redis node', function (): void {
        $redis = createBakeWebSocketRedisNode();

        $this->artisan('orbit:internal:bake-websocket-node', [
            'name' => 'app-dev-1',
            '--host' => 'dev',
            '--host-key-host' => '10.6.0.4',
            '--wireguard-address' => '10.6.0.4',
            '--gateway-endpoint' => 'gateway',
            '--user' => 'orbit',
            '--redis-node' => 'app-dev-1',
        ])
            ->expectsOutputToContain('__orbit_bake_timing websocket redis-node')
            ->expectsOutputToContain('__orbit_bake_timing websocket host-key')
            ->expectsOutputToContain('__orbit_bake_timing websocket registry')
            ->expectsOutputToContain('__orbit_bake_timing websocket role-assignment')
            ->assertSuccessful();

        $node = Node::query()->where('name', 'app-dev-1')->firstOrFail();
        $assignment = NodeRoleAssignment::query()
            ->where('node_id', $node->id)
            ->where('role', NodeRoleName::WebSocket->value)
            ->first();

        expect($node->getAttributes())->not->toHaveKeys(['role', 'environment'])
            ->and($node->id)->toBe($redis->id)
            ->and($node->tld)->toBe('test')
            ->and($node->host)->toBe('dev')
            ->and($node->wireguard_address)->toBe('10.6.0.4')
            ->and($node->gateway_endpoint)->toBe('gateway')
            ->and($node->user)->toBe('orbit')
            ->and($node->orbit_path)->toBe('/home/orbit/orbit')
            ->and($node->status)->toBe(NodeStatus::Active)
            ->and($node->host_key_type)->toBe('ssh-ed25519')
            ->and($node->host_key_public)->toBe('AAAAC3NzaC1lZDI1NTE5AAAAIBakeWebSocketNodeHostKey')
            ->and($node->host_key_fingerprint)->toBe('SHA256:bake-websocket-node-host-key')
            ->and($node->host_key_pin_mode)->toBe('tofu')
            ->and($node->host_key_pinned_at)->not->toBeNull()
            ->and($this->hostKeyPinner->calls)->toBe([
                ['host' => '10.6.0.4', 'expected' => null],
            ])
            ->and($assignment)->not->toBeNull()
            ->and($assignment?->status)->toBe(NodeRoleStatus::Active)
            ->and($assignment?->settings)->toBe(['redis_node_id' => $redis->id])
            ->and($assignment?->last_error)->toBeNull()
            ->and($assignment?->converged_at)->not->toBeNull();
    });

    it('requires an active database node with a Redis process', function (): void {
        Node::factory()->database()->create([
            'name' => 'app-dev-1',
            'status' => NodeStatus::Active,
        ]);

        expect(fn () => $this->artisan('orbit:internal:bake-websocket-node', [
            'name' => 'websocket-dedicated-1',
            '--host' => '10.6.0.8',
            '--wireguard-address' => '10.6.0.8',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
            '--redis-node' => 'app-dev-1',
        ])->run())->toThrow(RuntimeException::class, 'Active Redis node [app-dev-1] was not found.');
    });

    it('converges the websocket runtime baseline when requested', function (): void {
        $redis = createBakeWebSocketRedisNode();
        $timing = app(WebSocketRoleBaselineTiming::class);
        $converger = m::mock(NodeRoleBaselineConverger::class);
        $converger->shouldReceive('converge')
            ->once()
            ->with(
                m::on(fn (Node $node): bool => $node->name === 'app-dev-1'),
                m::on(fn (NodeRoleAssignment $assignment): bool => $assignment->role === NodeRoleName::WebSocket->value
                && $assignment->settings === ['redis_node_id' => $redis->id]),
            )
            ->andReturnUsing(function () use ($timing): void {
                foreach (['render', 'tools', 'certificates', 'source-install', 'container-apply'] as $step) {
                    $timing->measure($step, fn (): null => null);
                }
            });
        app()->instance(NodeRoleBaselineConverger::class, $converger);

        $this->artisan('orbit:internal:bake-websocket-node', [
            'name' => 'app-dev-1',
            '--host' => '10.6.0.4',
            '--wireguard-address' => '10.6.0.4',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
            '--redis-node' => 'app-dev-1',
            '--converge-runtime' => true,
        ])
            ->expectsOutputToContain('__orbit_bake_timing websocket runtime-converge')
            ->expectsOutputToContain('__orbit_bake_timing websocket runtime-render')
            ->expectsOutputToContain('__orbit_bake_timing websocket runtime-tools')
            ->expectsOutputToContain('__orbit_bake_timing websocket runtime-certificates')
            ->expectsOutputToContain('__orbit_bake_timing websocket runtime-source-install')
            ->expectsOutputToContain('__orbit_bake_timing websocket runtime-container-apply')
            ->assertSuccessful();
    });
});

function createBakeWebSocketRedisNode(): Node
{
    $node = Node::factory()->appDev(['tld' => 'test'])->create([
        'name' => 'app-dev-1',
        'status' => NodeStatus::Active,
    ]);

    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::Database->value,
        'status' => NodeRoleStatus::Active->value,
    ]);

    Process::factory()->forOwner($node)->create([
        'name' => 'redis',
        'runtime_config' => ['definition' => 'redis'],
    ]);

    return $node;
}
