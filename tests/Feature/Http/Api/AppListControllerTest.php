<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_LIST_CALLER_WG_IP = '10.6.0.99';

function createAppListCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_LIST_CALLER_WG_IP,
        'wireguard_address' => APP_LIST_CALLER_WG_IP,
    ], $overrides));
}

function createAppListAppNode(array $overrides = [], string $role = 'app-dev'): Node
{
    $node = Node::factory()->create($overrides);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $role === 'app-dev' ? ['tld' => 'test'] : [],
    ]);

    return $node;
}

function assignAppListGatewayRole(Node $node): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);
}

/**
 * @param  list<string>  $permissions
 */
function grantAppListAccess(Node $caller, Node $appNode, array $permissions = ['app:read']): void
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

describe('AppListController', function (): void {
    it('lists visible apps sorted by owning node then app name', function (): void {
        $caller = createAppListCallerNode();
        $zNode = createAppListAppNode(['name' => 'z-node']);
        $aNode = createAppListAppNode(['name' => 'a-node']);
        grantAppListAccess($caller, $zNode);
        grantAppListAccess($caller, $aNode);

        App::factory()->create(['name' => 'zebra', 'node_id' => $zNode->id, 'domain' => 'zebra.test']);
        App::factory()->create(['name' => 'beta', 'node_id' => $aNode->id, 'domain' => 'beta.test']);
        App::factory()->create(['name' => 'alpha', 'node_id' => $aNode->id, 'domain' => 'alpha.test']);

        $response = $this->call('GET', '/api/apps', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertOk();

        $apps = $response->json('success.data.apps');
        expect(array_column($apps, 'name'))->toBe(['alpha', 'beta', 'zebra']);
    });

    it('filters apps by owning node and environment', function (): void {
        $caller = createAppListCallerNode();
        $devNode = createAppListAppNode(['name' => 'dev-1']);
        $prodNode = createAppListAppNode(['name' => 'prod-1'], 'app-prod');
        grantAppListAccess($caller, $devNode);
        grantAppListAccess($caller, $prodNode);

        App::factory()->create(['name' => 'docs', 'node_id' => $devNode->id, 'environment' => 'development']);
        App::factory()->create(['name' => 'site', 'node_id' => $prodNode->id, 'environment' => 'production']);

        $response = $this->call('GET', '/api/apps?node=prod-1&environment=production', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.apps')
            ->assertJsonPath('success.data.apps.0.name', 'site');
    });

    it('omits hidden apps from the result', function (): void {
        $caller = createAppListCallerNode();
        $visibleNode = createAppListAppNode(['name' => 'visible-node']);
        $hiddenNode = createAppListAppNode(['name' => 'hidden-node']);
        grantAppListAccess($caller, $visibleNode);
        grantAppListAccess($caller, $hiddenNode, ['node:read']);

        App::factory()->create(['name' => 'visible', 'node_id' => $visibleNode->id]);
        App::factory()->create(['name' => 'hidden', 'node_id' => $hiddenNode->id]);

        $response = $this->call('GET', '/api/apps', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.apps')
            ->assertJsonPath('success.data.apps.0.name', 'visible');
    });

    it('lets active gateway role assignments read all app registry records', function (): void {
        $caller = createAppListCallerNode();
        assignAppListGatewayRole($caller);
        $firstNode = createAppListAppNode(['name' => 'app-1']);
        $secondNode = createAppListAppNode(['name' => 'app-2']);

        App::factory()->create(['name' => 'first', 'node_id' => $firstNode->id]);
        App::factory()->create(['name' => 'second', 'node_id' => $secondNode->id]);

        $response = $this->call('GET', '/api/apps', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(2, 'success.data.apps');
    });

    it('does not treat an unassigned caller as gateway visibility', function (): void {
        createAppListCallerNode();
        $node = createAppListAppNode(['name' => 'app-1']);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/apps', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('returns authorization failure when the caller has no app registry visibility', function (): void {
        $caller = createAppListCallerNode();
        $node = createAppListAppNode(['name' => 'app-1']);
        grantAppListAccess($caller, $node, ['node:read']);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/apps', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This node is not authorized to read the app registry.')
            ->assertJsonPath('error.meta.missing_permission', 'app:read');
    });

    it('returns validation error for invalid environment', function (): void {
        $caller = createAppListCallerNode();
        assignAppListGatewayRole($caller);

        $response = $this->call('GET', '/api/apps?environment=staging', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'environment')
            ->assertJsonPath('error.meta.allowed', ['development', 'production']);
    });

    it('returns the canonical app entity shape', function (): void {
        $caller = createAppListCallerNode();
        assignAppListGatewayRole($caller);
        $node = createAppListAppNode(['name' => 'app-1', 'tld' => 'test']);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'domain' => null,
            'path' => '/srv/docs',
            'document_root' => 'public',
            'repository' => null,
            'php_version' => '8.5',
            'adopted' => false,
        ]);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
        ]);

        $response = $this->call('GET', '/api/apps', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.apps.0', [
                'name' => 'docs',
                'node' => 'app-1',
                'url' => 'https://docs.test',
                'path' => '/srv/docs',
                'root' => 'public',
                'repository' => null,
                'runtime_kind' => 'php',
                'php_version' => '8.5',
                'worker_enabled' => false,
                'worker_config' => null,
                'adopted' => false,
                'workspaces' => [
                    [
                        'name' => 'feature-docs',
                        'url' => 'https://feature-docs.docs.test',
                        'lifecycle_status' => 'expected',
                    ],
                ],
            ]);
    });

    it('returns runtime_kind=static for static apps', function (): void {
        $caller = createAppListCallerNode();
        assignAppListGatewayRole($caller);
        $node = createAppListAppNode(['name' => 'app-1', 'tld' => 'test']);

        App::factory()->static()->create([
            'name' => 'marketing',
            'node_id' => $node->id,
            'domain' => null,
            'path' => '/srv/marketing',
            'document_root' => 'public',
            'php_version' => '8.5',
            'adopted' => false,
        ]);

        $response = $this->call('GET', '/api/apps', [], [], [], ['REMOTE_ADDR' => APP_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.apps.0.name', 'marketing')
            ->assertJsonPath('success.data.apps.0.runtime_kind', 'static');
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/apps');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
