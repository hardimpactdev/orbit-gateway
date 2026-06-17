<?php

declare(strict_types=1);

use App\Actions\Nodes\ReenactNodeArtifacts;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const UPDATE_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiUpdateNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.7',
        'public_ipv4' => null,
        'public_ipv6' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createUpdateCallerNode(string $name = 'operator-caller', ?string $role = null): int
{
    $nodeId = (int) DB::table('nodes')->insertGetId(apiUpdateNodeRow([
        'name' => $name,
        'host' => UPDATE_CALLER_WG_IP,
        'wireguard_address' => UPDATE_CALLER_WG_IP,
    ]));

    if ($role !== null) {
        assignNodeUpdateRole($nodeId, $role);
    }

    return $nodeId;
}

function createUpdateGatewayNode(): int
{
    $gatewayId = (int) DB::table('nodes')->insertGetId(apiUpdateNodeRow([
        'name' => 'gateway-1',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
    ]));

    assignNodeUpdateRole($gatewayId, 'gateway');

    return $gatewayId;
}

function assignNodeUpdateRole(int $nodeId, string $role, string $status = 'active'): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => $status,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function createApiUpdateNode(array $overrides = [], string $role = 'app-dev'): int
{
    $nodeId = (int) DB::table('nodes')->insertGetId(apiUpdateNodeRow($overrides));

    assignNodeUpdateRole($nodeId, $role);

    return $nodeId;
}

/**
 * @param  list<string>  $permissions
 */
function grantUpdateGatewayAccess(int $callerId, int $gatewayId, array $permissions = ['*']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $callerId,
        'serving_node_id' => $gatewayId,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  list<string>  $permissions
 */
function grantUpdateNodeAccess(int $consumerId, int $servingId, array $permissions): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $consumerId,
        'serving_node_id' => $servingId,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function putUpdateNodeJson(string $uri, array $data, array $server = []): TestResponse
{
    return test()->call(
        'PUT',
        $uri,
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

describe('NodeUpdateController', function (): void {
    it('updates a node for an authorized control caller', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', [
            'host' => '10.6.0.8',
            'public_ipv4' => '203.0.113.10',
        ], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'name' => 'app-1',
                        'changed' => ['host', 'public_ipv4'],
                        'action' => 'updated',
                    ],
                ],
            ]);

        $node = DB::table('nodes')->where('name', 'app-1')->first();

        expect($node->host)->toBe('10.6.0.8')
            ->and($node->public_ipv4)->toBe('203.0.113.10');
    });

    it('updates gateway endpoint metadata and re-enacts node artifacts', function (): void {
        $reenactor = new class extends ReenactNodeArtifacts
        {
            public ?string $nodeName = null;

            /** @var list<string> */
            public array $changed = [];

            public function handle(Node $node, array $changed): array
            {
                $this->nodeName = $node->name;
                $this->changed = $changed;

                return [];
            }
        };

        app()->instance(ReenactNodeArtifacts::class, $reenactor);

        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode(['gateway_endpoint' => '188.245.156.201']);

        $response = putUpdateNodeJson('/api/nodes/app-1', [
            'gateway_endpoint' => '10.3.0.2',
        ], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.changed', ['gateway_endpoint']);

        expect(DB::table('nodes')->where('name', 'app-1')->value('gateway_endpoint'))->toBe('10.3.0.2')
            ->and($reenactor->nodeName)->toBe('app-1')
            ->and($reenactor->changed)->toBe(['gateway_endpoint']);
    });

    it('logs activity for a successful node update write', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        $targetId = createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', [
            'host' => '10.6.0.8',
            'public_ipv4' => '203.0.113.10',
        ], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:PUT /nodes/{name}');
        expect($entry->subject_type)->toBe(Node::class);
        expect($entry->subject_id)->toBe($targetId);
        expect($entry->description)->toBe('Node app-1 updated');
        expect($entry->properties->get('type'))->toBe('write');
        expect($entry->properties->get('target_node'))->toBe('app-1');
        expect($entry->properties->get('changed_fields'))->toBe(['host', 'public_ipv4']);
    });

    it('updates a node directly for a gateway caller', function (): void {
        createUpdateCallerNode('gateway-caller', 'gateway');
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', [
            'host' => '10.6.0.8',
        ], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.changed', ['host']);
    });

    it('rejects callers without node update grants before mutation', function (): void {
        createUpdateCallerNode();
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', [
            'host' => '10.6.0.8',
        ], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:update')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(DB::table('nodes')->where('name', 'app-1')->value('host'))->toBe('10.6.0.7');
    });

    it('returns empty changed array for no-op updates', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', [
            'host' => '10.6.0.7',
        ], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.changed', []);
    });

    it('returns success warnings when artifact re-enactment fails after intent update', function (): void {
        app()->instance(ReenactNodeArtifacts::class, new class extends ReenactNodeArtifacts
        {
            public function handle(Node $node, array $changed): array
            {
                throw new RuntimeException('artifact failed');
            }
        });

        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', [
            'host' => '10.6.0.8',
        ], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.changed', ['host'])
            ->assertJsonPath('success.meta.warnings.0.code', 'node.artifact_enactment_failed')
            ->assertJsonPath('success.meta.warnings.0.family', 'node')
            ->assertJsonPath('success.meta.warnings.0.next_command', 'doctor --family=node --restore');

        expect(DB::table('nodes')->where('name', 'app-1')->value('host'))->toBe('10.6.0.8');
    });

    it('rejects unauthenticated requests', function (): void {
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', ['host' => '10.6.0.8']);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.')
            ->assertJsonPath('error.meta', []);
    });

    it('updates for app callers with explicit target node update grants', function (): void {
        $callerId = createUpdateCallerNode('app-dev-caller', 'app-dev');
        $targetId = createApiUpdateNode();
        grantUpdateNodeAccess($callerId, $targetId, ['node:update']);

        $response = putUpdateNodeJson('/api/nodes/app-1', ['host' => '10.6.0.8'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.changed', ['host']);

        expect(DB::table('nodes')->where('name', 'app-1')->value('host'))->toBe('10.6.0.8');
    });

    it('rejects database callers before mutation', function (): void {
        createUpdateCallerNode('database-caller', 'database');
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', ['host' => '10.6.0.8'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:update')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(DB::table('nodes')->where('name', 'app-1')->value('host'))->toBe('10.6.0.7');
    });

    it('updates for database callers with gateway admin grants', function (): void {
        $callerId = createUpdateCallerNode();
        assignNodeUpdateRole($callerId, 'database');
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', ['host' => '10.6.0.8'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.changed', ['host']);

        expect(DB::table('nodes')->where('name', 'app-1')->value('host'))->toBe('10.6.0.8');
    });

    it('rejects control callers without gateway access', function (): void {
        createUpdateCallerNode();
        createUpdateGatewayNode();
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', ['host' => '10.6.0.8'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:update')
            ->assertJsonPath('error.meta.serving_node', 'app-1');
    });

    it('checks control caller authorization against active gateway assignments', function (): void {
        $callerId = createUpdateCallerNode();
        $staleGatewayId = (int) DB::table('nodes')->insertGetId(apiUpdateNodeRow([
            'name' => 'alpha-gateway',
            'host' => '10.6.0.3',
            'wireguard_address' => '10.6.0.3',
        ]));
        grantUpdateGatewayAccess($callerId, $staleGatewayId);
        createUpdateGatewayNode();
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', ['host' => '10.6.0.8'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:update')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(DB::table('nodes')->where('name', 'app-1')->value('host'))->toBe('10.6.0.7');
    });

    it('returns validation error when no fields are provided', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', [], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'At least one field must be provided to update a node.')
            ->assertJsonPath('error.meta.field', 'fields');
    });

    it('rejects retired environment updates', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', ['environment' => 'staging'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', "Field 'environment' is not supported for node:update.")
            ->assertJsonPath('error.meta.field', 'environment');
    });

    it('rejects invalid gateway endpoint updates', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode(['gateway_endpoint' => '188.245.156.201']);

        $response = putUpdateNodeJson('/api/nodes/app-1', ['gateway_endpoint' => 'not a host'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', "Invalid value for --gateway-endpoint: 'not a host'. Gateway endpoint must be a valid IP address or dotted DNS name.")
            ->assertJsonPath('error.meta.field', 'gateway_endpoint')
            ->assertJsonPath('error.meta.value', 'not a host');

        expect(DB::table('nodes')->where('name', 'app-1')->value('gateway_endpoint'))->toBe('188.245.156.201');
    });

    it('rejects gateway endpoint updates for operator identity targets', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        DB::table('nodes')->insert(apiUpdateNodeRow([
            'name' => 'operator-target',
            'host' => '10.6.0.10',
            'wireguard_address' => '10.6.0.10',
            'gateway_endpoint' => null,
        ]));

        $response = putUpdateNodeJson('/api/nodes/operator-target', ['gateway_endpoint' => '10.3.0.2'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.field_role_incompatible')
            ->assertJsonPath('error.meta.field', 'gateway_endpoint')
            ->assertJsonPath('error.meta.name', 'operator-target')
            ->assertJsonPath('error.meta.role', 'operator');
    });

    it('returns validation error for role-incompatible fields', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);

        $response = putUpdateNodeJson('/api/nodes/gateway-1', ['tld' => 'test'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.field_role_incompatible')
            ->assertJsonPath('error.message', "The field 'tld' is not valid for node 'gateway-1' (role: gateway).")
            ->assertJsonPath('error.meta.field', 'tld')
            ->assertJsonPath('error.meta.name', 'gateway-1')
            ->assertJsonPath('error.meta.role', 'gateway');
    });

    it('returns not found for missing nodes', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);

        $response = putUpdateNodeJson('/api/nodes/missing-node', ['host' => '10.6.0.8'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.not_found')
            ->assertJsonPath('error.message', "Node 'missing-node' not found.")
            ->assertJsonPath('error.meta.name', 'missing-node');
    });

    it('updates the development tld for an app node', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode(['tld' => null], 'app-dev');

        $response = putUpdateNodeJson('/api/nodes/app-1', ['tld' => 'test'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.changed', ['tld']);

        expect(DB::table('nodes')->where('name', 'app-1')->value('tld'))->toBe('test');
    });

    it('updates the tld for an agent node', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode(['tld' => null], 'agent');

        $response = putUpdateNodeJson('/api/nodes/app-1', ['tld' => 'demo'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.changed', ['tld']);

        expect(DB::table('nodes')->where('name', 'app-1')->value('tld'))->toBe('demo');
    });

    it('rejects an invalid tld value', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode();

        $response = putUpdateNodeJson('/api/nodes/app-1', ['tld' => 'Invalid_TLD!'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'tld')
            ->assertJsonPath('error.meta.value', 'Invalid_TLD!');
    });

    it('rejects tld on a production app node as role-incompatible', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode(role: 'app-prod');

        $response = putUpdateNodeJson('/api/nodes/app-1', ['tld' => 'test'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.field_role_incompatible')
            ->assertJsonPath('error.meta.field', 'tld');
    });

    it('rejects tld already assigned to another active node', function (): void {
        $callerId = createUpdateCallerNode();
        $gatewayId = createUpdateGatewayNode();
        grantUpdateGatewayAccess($callerId, $gatewayId);
        createApiUpdateNode(['tld' => null], 'app-dev');
        createApiUpdateNode(['name' => 'app-2', 'tld' => 'test'], 'app-dev');

        $response = putUpdateNodeJson('/api/nodes/app-1', ['tld' => 'test'], ['REMOTE_ADDR' => UPDATE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.tld_in_use')
            ->assertJsonPath('error.meta.field', 'tld')
            ->assertJsonPath('error.meta.value', 'test');
    });
});
