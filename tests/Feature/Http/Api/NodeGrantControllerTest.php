<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const GRANT_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiGrantNodeRow(array $overrides = []): array
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

function createGrantCallerNode(?string $role = null): int
{
    $name = $role === null ? 'control-caller' : "{$role}-caller";

    $nodeId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
        'name' => $name,
        'host' => GRANT_CALLER_WG_IP,
        'wireguard_address' => GRANT_CALLER_WG_IP,
    ]));

    if ($role !== null) {
        assignApiGrantNodeRole($nodeId, $role);
    }

    return $nodeId;
}

function createGrantGatewayNode(): int
{
    $nodeId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
        'name' => 'gateway-1',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
    ]));

    assignApiGrantGatewayRole($nodeId);

    return $nodeId;
}

/**
 * @param  list<string>  $permissions
 */
function grantGatewayManagementAccess(int $callerId, int $gatewayId, array $permissions = ['node:grant']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $callerId,
        'serving_node_id' => $gatewayId,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignApiGrantGatewayRole(int $nodeId): void
{
    assignApiGrantNodeRole($nodeId, 'gateway');
}

function assignApiGrantNodeRole(int $nodeId, string $role, string $status = 'active'): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => $status,
    ]);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function postNodeGrantJson(array $data, array $server = []): TestResponse
{
    return test()->call(
        'POST',
        '/api/nodes/grant',
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

describe('NodeGrantController', function (): void {
    it('creates a grant for an authorized control caller', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'granted',
                        'already_granted' => false,
                    ],
                ],
            ]);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('logs activity for a successful grant write', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:POST /nodes/grant');
        expect($entry->subject_type)->toBe(Node::class);
        expect($entry->subject_id)->toBe($servingId);
        expect($entry->description)->toBe('control-1 granted access to app-1');
        expect($entry->properties->get('type'))->toBe('write');
        expect($entry->properties->get('consuming_node'))->toBe('control-1');
        expect($entry->properties->get('serving_node'))->toBe('app-1');
    });

    it('creates a grant directly for a gateway caller', function (): void {
        createGrantCallerNode('gateway');
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.already_granted', false);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('creates a grant directly for a caller with an assigned gateway role', function (): void {
        $callerId = createGrantCallerNode();
        assignApiGrantGatewayRole($callerId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.already_granted', false);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('returns idempotent success when the grant already exists', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        DB::table('node_access')->insert([
            'consumer_node_id' => $consumingId,
            'serving_node_id' => $servingId,
            'permissions' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'granted')
            ->assertJsonPath('success.data.already_granted', true);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->count())->toBe(1);
    });

    it('rejects unauthenticated requests', function (): void {
        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.')
            ->assertJsonPath('error.meta', []);
    });

    it('rejects callers without node grant permission before mutation', function (): void {
        createGrantCallerNode('app-dev');
        createGrantGatewayNode();
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:grant')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');

        expect(DB::table('node_access')->count())->toBe(0);
    });

    it('rejects gateway-named callers without gateway assignment before mutation', function (): void {
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'gateway-caller',
            'host' => GRANT_CALLER_WG_IP,
            'wireguard_address' => GRANT_CALLER_WG_IP,
        ]));
        createGrantGatewayNode();
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'node:grant')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeFalse();
    });

    it('allows database assigned callers with node grant permission', function (): void {
        $callerId = createGrantCallerNode();
        assignApiGrantNodeRole($callerId, 'database');
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.already_granted', false);

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('rejects callers without gateway grant before mutation', function (): void {
        createGrantCallerNode();
        createGrantGatewayNode();
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:grant')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');

        expect(DB::table('node_access')->count())->toBe(0);
    });

    it('authorizes control callers through gateways represented by an active role assignment', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'gateway-1',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
        ]));
        assignApiGrantGatewayRole($gatewayId);
        grantGatewayManagementAccess($callerId, $gatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk();

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('authorizes control callers through any active gateway they can access', function (): void {
        $callerId = createGrantCallerNode();
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'aaa-gateway',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
        ]));
        $authorizedGatewayId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'zzz-gateway',
            'host' => '10.6.0.3',
            'wireguard_address' => '10.6.0.3',
        ]));
        assignApiGrantGatewayRole($authorizedGatewayId);
        grantGatewayManagementAccess($callerId, $authorizedGatewayId);
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk();

        expect(DB::table('node_access')
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->exists())->toBeTrue();
    });

    it('rejects missing consuming node input', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);

        $response = postNodeGrantJson([
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Consuming node is required.')
            ->assertJsonPath('error.meta.field', 'consuming_node');
    });

    it('rejects missing serving node input', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Serving node is required.')
            ->assertJsonPath('error.meta.field', 'serving_node');
    });

    it('rejects missing consuming nodes as not found', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiGrantNodeRow(['name' => 'app-1']));

        $response = postNodeGrantJson([
            'consuming_node' => 'missing-control',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.not_found')
            ->assertJsonPath('error.message', "Consuming node 'missing-control' not found.")
            ->assertJsonPath('error.meta.field', 'consuming_node')
            ->assertJsonPath('error.meta.name', 'missing-control');
    });

    it('rejects provisioning serving nodes as not found', function (): void {
        $callerId = createGrantCallerNode();
        $gatewayId = createGrantGatewayNode();
        grantGatewayManagementAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'app-1',
            'status' => 'provisioning',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.not_found')
            ->assertJsonPath('error.message', "Serving node 'app-1' not found.")
            ->assertJsonPath('error.meta.field', 'serving_node')
            ->assertJsonPath('error.meta.name', 'app-1');

        expect(DB::table('node_access')->count())->toBe(1);
    });

    it('allows self-grants', function (): void {
        createGrantCallerNode('gateway');
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'control-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'granted')
            ->assertJsonPath('success.data.already_granted', false);

        expect(DB::table('node_access')->count())->toBe(1);
    });

    it('creates a grant with preset permissions', function (): void {
        createGrantCallerNode('gateway');
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'granted')
            ->assertJsonPath('success.data.already_granted', false);

        $grant = NodeAccess::query()
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->first();

        expect($grant)->not->toBeNull();
        expect($grant->permissions)->toBe(['app:read', 'database:read', 'doctor:verify', 'firewall_rule:read', 'node:read', 'tool:read']);
    });

    it('requires force for gateway-admin grants', function (): void {
        createGrantCallerNode('gateway');
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'target-gateway',
            'host' => '10.6.0.12',
            'wireguard_address' => '10.6.0.12',
        ]));
        assignApiGrantGatewayRole($gatewayId);
        DB::table('nodes')->insert(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'target-gateway',
            'preset' => 'gateway-admin',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force');
    });

    it('allows gateway-admin grants with force', function (): void {
        createGrantCallerNode('gateway');
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'target-gateway',
            'host' => '10.6.0.12',
            'wireguard_address' => '10.6.0.12',
        ]));
        assignApiGrantGatewayRole($servingId);

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'target-gateway',
            'preset' => 'gateway-admin',
            'force' => true,
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'granted');

        $grant = NodeAccess::query()
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->first();

        expect($grant)->not->toBeNull();
        expect($grant->permissions)->toBe(['*']);
    });

    it('does not modify existing grant permissions', function (): void {
        createGrantCallerNode('gateway');
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        DB::table('node_access')->insert([
            'consumer_node_id' => $consumingId,
            'serving_node_id' => $servingId,
            'permissions' => json_encode(['node:read']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = postNodeGrantJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => GRANT_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'granted')
            ->assertJsonPath('success.data.already_granted', true)
            ->assertJsonMissingPath('success.meta');

        $grant = NodeAccess::query()
            ->where('consumer_node_id', $consumingId)
            ->where('serving_node_id', $servingId)
            ->first();

        expect($grant->permissions)->toBe(['node:read']);
    });

    it('stores normalized permissions', function (): void {
        $consumingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $servingId = (int) DB::table('nodes')->insertGetId(apiGrantNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $grant = NodeAccess::query()->create([
            'consumer_node_id' => $consumingId,
            'serving_node_id' => $servingId,
            'permissions' => ['app:read', 'app:write'],
        ]);

        $defaultedGrantId = (int) DB::table('node_access')->insertGetId([
            'consumer_node_id' => $servingId,
            'serving_node_id' => $consumingId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect($grant->fresh()?->permissions)
            ->toBe(['app:read', 'app:write'])
            ->and(NodeAccess::query()->find($defaultedGrantId)?->permissions)
            ->toBe(['*']);
    });
});
