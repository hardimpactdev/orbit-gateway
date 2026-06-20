<?php

declare(strict_types=1);

use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeAccessCommandRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function assignNodeAccessCommandRole(int $nodeId, string $role, string $status = 'active'): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => $status,
    ]);
}

function setupNodeAccessRevokeNodes(): array
{

    $gatewayId = (int) DB::table('nodes')->insertGetId(nodeAccessCommandRow([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.2',
    ]));
    assignNodeAccessCommandRole($gatewayId, 'gateway');

    $consumerId = (int) DB::table('nodes')->insertGetId(nodeAccessCommandRow([
        'name' => 'control-1',
        'wireguard_address' => '10.6.0.8',
    ]));

    $servingId = (int) DB::table('nodes')->insertGetId(nodeAccessCommandRow([
        'name' => 'app-1',
        'wireguard_address' => '10.6.0.12',
    ]));

    DB::table('node_access')->insert([
        'consumer_node_id' => $consumerId,
        'serving_node_id' => $gatewayId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$gatewayId, $consumerId, $servingId];
}

describe('node access revoke integration', function (): void {
    it('revokes an existing grant through the gateway API path', function (): void {
        [, $consumerId, $servingId] = setupNodeAccessRevokeNodes();

        DB::table('node_access')->insert([
            'consumer_node_id' => $consumerId,
            'serving_node_id' => $servingId,
        ]);

        $response = \Pest\Laravel\call('POST', '/api/nodes/revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertExactJson([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => false,
                        'was_gateway_admin' => true,
                    ],
                ],
            ]);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumerId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeFalse();
    });

    it('keeps repeated revokes idempotent with stable state', function (): void {
        [, $consumerId, $servingId] = setupNodeAccessRevokeNodes();

        DB::table('node_access')->insert([
            'consumer_node_id' => $consumerId,
            'serving_node_id' => $servingId,
        ]);

        \Pest\Laravel\call('POST', '/api/nodes/revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], [], [], ['REMOTE_ADDR' => '10.6.0.8'])->assertOk();

        $response = \Pest\Laravel\call('POST', '/api/nodes/revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'revoked')
            ->assertJsonPath('success.data.already_absent', true)
            ->assertJsonPath('success.data.self_lockout', false);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumerId)
            ->where('serving_node_id', $servingId)
            ->count())->toBe(0);
    });

    it('reports self-lockout when a control caller revokes its own gateway grant', function (): void {
        [$gatewayId, $consumerId] = setupNodeAccessRevokeNodes();

        $response = \Pest\Laravel\call('POST', '/api/nodes/revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'gateway-1',
            'force' => true,
        ], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertExactJson([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'gateway-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => true,
                        'was_gateway_admin' => true,
                    ],
                ],
            ]);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumerId)
            ->where('serving_node_id', $gatewayId)
            ->exists())->toBeFalse();
    });
});
