<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const REVOKE_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiRevokeNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.7',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createRevokeCallerNode(?string $role = null): int
{
    $name = $role === null ? 'control-caller' : "{$role}-caller";

    $nodeId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
        'name' => $name,
        'host' => REVOKE_CALLER_WG_IP,
        'wireguard_address' => REVOKE_CALLER_WG_IP,
    ]));

    if ($role !== null) {
        assignRevokeNodeRole($nodeId, $role);
    }

    return $nodeId;
}

function createRevokeGatewayNode(): int
{
    $gatewayId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
        'name' => 'gateway-1',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
    ]));

    assignRevokeNodeRole($gatewayId, 'gateway');

    return $gatewayId;
}

function assignRevokeNodeRole(int $nodeId, string $role, string $status = 'active'): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => $status,
    ]);
}

/**
 * @param  list<string>  $permissions
 */
function grantRevokeAccess(int $consumerId, int $servingId, array $permissions = ['node:revoke']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $consumerId,
        'serving_node_id' => $servingId,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function postNodeRevokeJson(array $data, array $server = []): TestResponse
{
    return test()->call(
        'POST',
        '/api/nodes/revoke',
        $data,
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server),
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

describe('NodeRevokeController', function (): void {
    it('revokes an existing grant for an authorized control caller', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => false,
                        'was_gateway_admin' => false,
                    ],
                ],
            ]);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeFalse();
    });

    it('logs activity for a successful grant revocation', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:POST /nodes/revoke');
        expect($entry->subject_type)->toBe(Node::class);
        expect($entry->subject_id)->toBe($servingId);
        expect($entry->description)->toBe('control-1 revoked access to app-1');
        expect($entry->properties->get('type'))->toBe('destructive');
        expect($entry->properties->get('consuming_node'))->toBe('control-1');
        expect($entry->properties->get('serving_node'))->toBe('app-1');
        expect($entry->properties->get('self_lockout'))->toBeFalse();
    });

    it('revokes directly for a gateway caller', function (): void {
        createRevokeCallerNode('gateway');
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'revoked')
            ->assertJsonPath('success.data.already_absent', false)
            ->assertJsonPath('success.data.was_gateway_admin', false);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeFalse();
    });

    it('rejects gateway-named callers without gateway assignment before mutation', function (): void {
        DB::table('nodes')->insert(apiRevokeNodeRow([
            'name' => 'gateway-caller',
            'host' => REVOKE_CALLER_WG_IP,
            'wireguard_address' => REVOKE_CALLER_WG_IP,
        ]));
        createRevokeGatewayNode();
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:revoke')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('allows database assigned callers with node revoke permission', function (): void {
        $callerId = createRevokeCallerNode();
        assignRevokeNodeRole($callerId, 'database');
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'revoked')
            ->assertJsonPath('success.data.already_absent', false);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeFalse();
    });

    it('checks control caller authorization against active gateway assignments', function (): void {
        $callerId = createRevokeCallerNode();
        $staleGatewayId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'alpha-gateway',
            'host' => '10.6.0.3',
            'wireguard_address' => '10.6.0.3',
        ]));
        grantRevokeAccess($callerId, $staleGatewayId);
        createRevokeGatewayNode();
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:revoke')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('returns idempotent success when the grant is already absent', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiRevokeNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'revoked')
            ->assertJsonPath('success.data.already_absent', true)
            ->assertJsonPath('success.data.self_lockout', false)
            ->assertJsonPath('success.data.was_gateway_admin', false);
    });

    it('reports self lockout when a control caller revokes its own gateway access', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-caller',
            'serving_node' => 'gateway-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.consuming_node', 'control-caller')
            ->assertJsonPath('success.data.serving_node', 'gateway-1')
            ->assertJsonPath('success.data.action', 'revoked')
            ->assertJsonPath('success.data.already_absent', false)
            ->assertJsonPath('success.data.self_lockout', true)
            ->assertJsonPath('success.data.was_gateway_admin', false);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $callerId)
            ->where('serving_node_id', $gatewayId)
            ->exists())->toBeFalse();
    });

    it('reports when the revoked grant was a gateway admin grant', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId, ['*']);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'revoked')
            ->assertJsonPath('success.data.already_absent', false)
            ->assertJsonPath('success.data.self_lockout', false)
            ->assertJsonPath('success.data.was_gateway_admin', true);
    });

    it('rejects unauthenticated requests', function (): void {
        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.')
            ->assertJsonPath('error.meta', []);
    });

    it('rejects callers without node revoke permission before mutation', function (): void {
        createRevokeCallerNode('app-dev');
        createRevokeGatewayNode();
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:revoke')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('rejects callers without gateway grant before mutation', function (): void {
        createRevokeCallerNode();
        createRevokeGatewayNode();
        $consumingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiRevokeNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));
        grantRevokeAccess($consumingId, $servingId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:revoke')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('rejects requests without destructive consent', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Use --force to revoke this grant.')
            ->assertJsonPath('error.meta.field', 'force');
    });

    it('rejects missing consuming node input', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);

        $response = postNodeRevokeJson([
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Consuming node is required.')
            ->assertJsonPath('error.meta.field', 'consuming_node');
    });

    it('rejects missing serving node input', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);

        $response = postNodeRevokeJson([
            'consuming_node' => 'control-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Serving node is required.')
            ->assertJsonPath('error.meta.field', 'serving_node');
    });

    it('returns node not found for missing endpoint nodes', function (): void {
        $callerId = createRevokeCallerNode();
        $gatewayId = createRevokeGatewayNode();
        grantRevokeAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiRevokeNodeRow(['name' => 'app-1']));

        $response = postNodeRevokeJson([
            'consuming_node' => 'missing-control',
            'serving_node' => 'app-1',
            'force' => true,
        ], ['REMOTE_ADDR' => REVOKE_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.not_found')
            ->assertJsonPath('error.message', "Node 'missing-control' not found.")
            ->assertJsonPath('error.meta.name', 'missing-control');
    });
});
