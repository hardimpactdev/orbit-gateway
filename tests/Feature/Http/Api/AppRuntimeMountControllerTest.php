<?php

declare(strict_types=1);

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const APP_RUNTIME_MOUNT_CALLER_WG_IP = '10.6.0.97';

function createAppRuntimeMountCaller(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_RUNTIME_MOUNT_CALLER_WG_IP,
        'wireguard_address' => APP_RUNTIME_MOUNT_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantAppRuntimeMountAccess(Node $caller, Node $appNode, array $permissions = ['app:mount']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function postAppRuntimeMountJson(string $uri, array $data): TestResponse
{
    return test()->call(
        'POST',
        $uri,
        $data,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_RUNTIME_MOUNT_CALLER_WG_IP,
        ],
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

/**
 * @param  array<string, mixed>  $data
 */
function deleteAppRuntimeMountJson(string $uri, array $data): TestResponse
{
    return test()->call(
        'DELETE',
        $uri,
        $data,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_RUNTIME_MOUNT_CALLER_WG_IP,
        ],
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

describe('AppRuntimeMountController', function (): void {
    it('adds lists updates and removes app runtime mounts for app-dev PHP apps', function (): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = Node::factory()->appDev()->create(['name' => 'beast', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode, ['app:read', 'app:mount']);
        App::factory()->for($appNode, 'node')->create([
            'name' => 'nckrtl',
            'path' => '/home/nckrtl/apps/nckrtl',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);

        $created = postAppRuntimeMountJson('/api/apps/nckrtl/mounts', [
            'source' => '/home/nckrtl/packages',
            'target' => '/home/nckrtl/packages',
        ]);

        $created->assertOk()
            ->assertJsonPath('success.data.action', 'created')
            ->assertJsonPath('success.data.mount.source', '/home/nckrtl/packages')
            ->assertJsonPath('success.data.mount.target', '/home/nckrtl/packages')
            ->assertJsonPath('success.data.mount.read_only', true)
            ->assertJsonPath('success.data.inherited_by_workspaces', true);

        $updated = postAppRuntimeMountJson('/api/apps/nckrtl/mounts', [
            'source' => '/home/nckrtl/packages',
            'target' => '/home/nckrtl/packages',
            'read_only' => false,
        ]);

        $updated->assertOk()
            ->assertJsonPath('success.data.action', 'updated')
            ->assertJsonPath('success.data.mount.read_only', false);

        $list = $this->call('GET', '/api/apps/nckrtl/mounts', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_RUNTIME_MOUNT_CALLER_WG_IP,
        ]);

        $list->assertOk()
            ->assertJsonPath('success.data.app.name', 'nckrtl')
            ->assertJsonPath('success.data.mounts.0.source', '/home/nckrtl/packages')
            ->assertJsonPath('success.data.mounts.0.target', '/home/nckrtl/packages')
            ->assertJsonPath('success.data.mounts.0.read_only', false);

        $removed = deleteAppRuntimeMountJson('/api/apps/nckrtl/mounts', [
            'target' => '/home/nckrtl/packages',
        ]);

        $removed->assertOk()
            ->assertJsonPath('success.data.action', 'removed')
            ->assertJsonPath('success.data.mounts', []);
    });

    it('rejects mount mutations without app mount permission', function (): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = Node::factory()->appDev()->create(['name' => 'beast', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode, ['app:read']);
        App::factory()->for($appNode, 'node')->create(['name' => 'nckrtl']);

        $response = postAppRuntimeMountJson('/api/apps/nckrtl/mounts', [
            'source' => '/home/nckrtl/packages',
            'target' => '/home/nckrtl/packages',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:mount');
    });

    it('rejects unsafe runtime mount intent before persistence', function (array $payload, string $reason): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = Node::factory()->appDev()->create(['name' => 'beast', 'user' => 'nckrtl']);
        grantAppRuntimeMountAccess($caller, $appNode);
        App::factory()->for($appNode, 'node')->create([
            'name' => 'nckrtl',
            'path' => '/home/nckrtl/apps/nckrtl',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);

        $response = postAppRuntimeMountJson('/api/apps/nckrtl/mounts', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', $reason);

        expect(DB::table('app_runtime_mounts')->count())->toBe(0);
    })->with([
        'relative source' => [[
            'source' => 'packages',
            'target' => '/home/nckrtl/packages',
        ], 'source_must_be_absolute'],
        'outside node home' => [[
            'source' => '/srv/shared',
            'target' => '/srv/shared',
        ], 'source_outside_app_dev_home'],
        'secret source' => [[
            'source' => '/home/nckrtl/.ssh',
            'target' => '/home/nckrtl/.ssh',
        ], 'source_sensitive'],
        'reserved target' => [[
            'source' => '/home/nckrtl/packages',
            'target' => '/packages',
        ], 'target_reserved'],
    ]);

    it('rejects configurable runtime mounts for static apps and app-prod apps in the first slice', function (array $nodeState, array $appState, string $reason): void {
        $caller = createAppRuntimeMountCaller();
        $appNode = Node::factory()
            ->{$nodeState['role']}()
            ->create(['name' => 'app-node', 'user' => 'orbit']);
        grantAppRuntimeMountAccess($caller, $appNode);
        App::factory()->for($appNode, 'node')->create(array_merge([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
        ], $appState));

        $response = postAppRuntimeMountJson('/api/apps/docs/mounts', [
            'source' => '/home/orbit/packages',
            'target' => '/home/orbit/packages',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', $reason);
    })->with([
        'static app' => [['role' => 'appDev'], ['runtime_kind' => AppRuntimeKind::Static], 'app_runtime_kind_not_php'],
        'app-prod app' => [['role' => 'appProd'], ['environment' => 'production'], 'app_mounts_app_dev_only'],
    ]);
});
