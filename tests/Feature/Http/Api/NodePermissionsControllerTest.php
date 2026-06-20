<?php

declare(strict_types=1);

use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const PERMS_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiPermsNodeRow(array $overrides = []): array
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

function createPermsCallerNode(string $role = 'gateway'): int
{
    $nodeId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
        'name' => "{$role}-caller",
        'host' => PERMS_CALLER_WG_IP,
        'wireguard_address' => PERMS_CALLER_WG_IP,
    ]));

    if ($role === 'gateway') {
        assignPermsGatewayRole($nodeId);
    }

    return $nodeId;
}

function assignPermsGatewayRole(int $nodeId): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => 'gateway',
        'status' => 'active',
    ]);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function postNodePermissionsJson(array $data, array $server = []): TestResponse
{
    return test()->call(
        'POST',
        '/api/nodes/permissions',
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

describe('NodePermissionsController', function (): void {
    it('reads permissions for an existing grant', function (): void {
        createPermsCallerNode('gateway');
        $controlId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $appId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlId,
            'serving_node_id' => $appId,
            'permissions' => json_encode(['node:read', 'tool:read']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = postNodePermissionsJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => PERMS_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'read')
            ->assertJsonPath('success.data.permissions', ['node:read', 'tool:read']);
    });

    it('fails with node.grant_not_found for missing grant in read mode', function (): void {
        createPermsCallerNode('gateway');
        DB::table('nodes')->insert(apiPermsNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiPermsNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodePermissionsJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => PERMS_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.grant_not_found');
    });

    it('updates permissions with preset', function (): void {
        createPermsCallerNode('gateway');
        $controlId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $appId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlId,
            'serving_node_id' => $appId,
            'permissions' => json_encode(['node:read']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = postNodePermissionsJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => PERMS_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'updated');

        $grant = NodeAccess::query()
            ->where('consumer_node_id', $controlId)
            ->where('serving_node_id', $appId)
            ->first();

        expect($grant->permissions)->toBe(['app:read', 'database:read', 'doctor:verify', 'firewall_rule:read', 'node:read', 'tool:read']);
    });

    it('creates grant with preset when missing', function (): void {
        createPermsCallerNode('gateway');
        $controlId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $appId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodePermissionsJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => PERMS_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'created');

        $grant = NodeAccess::query()
            ->where('consumer_node_id', $controlId)
            ->where('serving_node_id', $appId)
            ->first();

        expect($grant)->not->toBeNull();
    });

    it('removes permissions and preserves grant edge', function (): void {
        createPermsCallerNode('gateway');
        $controlId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $appId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlId,
            'serving_node_id' => $appId,
            'permissions' => json_encode(['node:read', 'tool:read']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = postNodePermissionsJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'remove' => 'tool:read',
        ], ['REMOTE_ADDR' => PERMS_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'updated')
            ->assertJsonPath('success.data.permissions', ['node:read']);

        expect(DB::table('node_access')->count())->toBe(1);
    });

    it('fails with node.grant_not_found when removing from missing grant', function (): void {
        createPermsCallerNode('gateway');
        DB::table('nodes')->insert(apiPermsNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiPermsNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodePermissionsJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'remove' => 'node:read',
        ], ['REMOTE_ADDR' => PERMS_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.grant_not_found');
    });

    it('allows non-gateway callers with node read permission to read permissions', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));
        assignPermsGatewayRole($gatewayId);

        $callerId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'control-caller',
            'host' => PERMS_CALLER_WG_IP,
            'wireguard_address' => PERMS_CALLER_WG_IP,
        ]));

        DB::table('node_access')->insert([
            'consumer_node_id' => $callerId,
            'serving_node_id' => $gatewayId,
            'permissions' => json_encode(['node:read']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $controlId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $appId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlId,
            'serving_node_id' => $appId,
            'permissions' => json_encode(['node:read']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = postNodePermissionsJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ], ['REMOTE_ADDR' => PERMS_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'read')
            ->assertJsonPath('success.data.permissions', ['node:read']);
    });

    it('allows non-gateway callers with node permissions authority to manage permissions', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));
        assignPermsGatewayRole($gatewayId);

        $callerId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'control-caller',
            'host' => PERMS_CALLER_WG_IP,
            'wireguard_address' => PERMS_CALLER_WG_IP,
        ]));

        DB::table('node_access')->insert([
            'consumer_node_id' => $callerId,
            'serving_node_id' => $gatewayId,
            'permissions' => json_encode(['node:permissions']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $controlId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $appId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlId,
            'serving_node_id' => $appId,
            'permissions' => json_encode(['node:read']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = postNodePermissionsJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => PERMS_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'updated');
    });

    it('rejects non-gateway caller with only node:grant permission', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));
        assignPermsGatewayRole($gatewayId);

        $callerId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'control-caller',
            'host' => PERMS_CALLER_WG_IP,
            'wireguard_address' => PERMS_CALLER_WG_IP,
        ]));

        DB::table('node_access')->insert([
            'consumer_node_id' => $callerId,
            'serving_node_id' => $gatewayId,
            'permissions' => json_encode(['node:grant']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('nodes')->insert(apiPermsNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiPermsNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodePermissionsJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
        ], ['REMOTE_ADDR' => PERMS_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This action requires the node:permissions permission on a grant to the gateway.')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:permissions')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');
    });

    it('fails with validation_failed for non-string preset', function (): void {
        createPermsCallerNode('gateway');
        DB::table('nodes')->insert(apiPermsNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiPermsNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodePermissionsJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => ['operator'],
        ], ['REMOTE_ADDR' => PERMS_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'preset');
    });

    it('fails with validation_failed for non-string permissions', function (): void {
        createPermsCallerNode('gateway');
        DB::table('nodes')->insert(apiPermsNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        DB::table('nodes')->insert(apiPermsNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        $response = postNodePermissionsJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'permissions' => true,
        ], ['REMOTE_ADDR' => PERMS_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'permissions');
    });

    it('fails with validation_failed for multiple mode flags', function (): void {
        createPermsCallerNode('gateway');
        $controlId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'control-1',
            'wireguard_address' => '10.6.0.11',
        ]));
        $appId = (int) DB::table('nodes')->insertGetId(apiPermsNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.12',
        ]));

        DB::table('node_access')->insert([
            'consumer_node_id' => $controlId,
            'serving_node_id' => $appId,
            'permissions' => json_encode(['node:read']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = postNodePermissionsJson([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
            'permissions' => 'node:read,tool:read',
        ], ['REMOTE_ADDR' => PERMS_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
    });
});
