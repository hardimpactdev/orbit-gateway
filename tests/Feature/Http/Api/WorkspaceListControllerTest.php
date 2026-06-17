<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_LIST_CALLER_WG_IP = '10.6.0.97';

function createWorkspaceListCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => WORKSPACE_LIST_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_LIST_CALLER_WG_IP,
    ], $overrides));
}

function createWorkspaceListAppNode(array $overrides = [], string $role = 'app-dev'): Node
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

function assignWorkspaceListGatewayRole(Node $node): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);
}

function grantWorkspaceListAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['workspace:read'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('WorkspaceListController', function (): void {
    it('lists visible workspaces sorted by node then app then workspace name', function (): void {
        $caller = createWorkspaceListCallerNode();
        $zNode = createWorkspaceListAppNode(['name' => 'z-node']);
        $aNode = createWorkspaceListAppNode(['name' => 'a-node']);
        grantWorkspaceListAccess($caller, $zNode);
        grantWorkspaceListAccess($caller, $aNode);

        $zApp = App::factory()->create(['name' => 'zebra', 'node_id' => $zNode->id, 'domain' => 'zebra.test']);
        $bApp = App::factory()->create(['name' => 'beta', 'node_id' => $aNode->id, 'domain' => 'beta.test']);
        $aApp = App::factory()->create(['name' => 'alpha', 'node_id' => $aNode->id, 'domain' => 'alpha.test']);

        Workspace::factory()->create(['name' => 'z-workspace', 'app_id' => $zApp->id]);
        Workspace::factory()->create(['name' => 'beta-two', 'app_id' => $bApp->id]);
        Workspace::factory()->create(['name' => 'alpha-one', 'app_id' => $aApp->id]);
        Workspace::factory()->create(['name' => 'beta-one', 'app_id' => $bApp->id]);

        $response = $this->call('GET', '/api/workspaces', [], [], [], ['REMOTE_ADDR' => WORKSPACE_LIST_CALLER_WG_IP]);

        $response->assertOk();

        $workspaces = $response->json('success.data.workspaces');
        expect(array_column($workspaces, 'name'))->toBe(['alpha-one', 'beta-one', 'beta-two', 'z-workspace']);
    });

    it('filters workspaces by app and node', function (): void {
        $caller = createWorkspaceListCallerNode();
        $devNode = createWorkspaceListAppNode(['name' => 'dev-1']);
        $prodNode = createWorkspaceListAppNode(['name' => 'prod-1'], 'app-prod');
        grantWorkspaceListAccess($caller, $devNode);
        grantWorkspaceListAccess($caller, $prodNode);

        $docs = App::factory()->create(['name' => 'docs', 'node_id' => $devNode->id]);
        $site = App::factory()->create(['name' => 'site', 'node_id' => $prodNode->id]);
        Workspace::factory()->create(['name' => 'docs-feature', 'app_id' => $docs->id]);
        Workspace::factory()->create(['name' => 'site-feature', 'app_id' => $site->id]);

        $response = $this->call('GET', '/api/workspaces?app=docs&node=dev-1', [], [], [], ['REMOTE_ADDR' => WORKSPACE_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.workspaces')
            ->assertJsonPath('success.data.workspaces.0.name', 'docs-feature');
    });

    it('omits hidden workspaces from the result', function (): void {
        $caller = createWorkspaceListCallerNode();
        $visibleNode = createWorkspaceListAppNode(['name' => 'visible-node']);
        $hiddenNode = createWorkspaceListAppNode(['name' => 'hidden-node']);
        grantWorkspaceListAccess($caller, $visibleNode);

        $visibleApp = App::factory()->create(['name' => 'visible', 'node_id' => $visibleNode->id]);
        $hiddenApp = App::factory()->create(['name' => 'hidden', 'node_id' => $hiddenNode->id]);
        Workspace::factory()->create(['name' => 'visible-workspace', 'app_id' => $visibleApp->id]);
        Workspace::factory()->create(['name' => 'hidden-workspace', 'app_id' => $hiddenApp->id]);

        $response = $this->call('GET', '/api/workspaces', [], [], [], ['REMOTE_ADDR' => WORKSPACE_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.workspaces')
            ->assertJsonPath('success.data.workspaces.0.name', 'visible-workspace');
    });

    it('lets active gateway role assignments read all workspace registry records', function (): void {
        $caller = createWorkspaceListCallerNode();
        assignWorkspaceListGatewayRole($caller);
        $firstNode = createWorkspaceListAppNode(['name' => 'app-1']);
        $secondNode = createWorkspaceListAppNode(['name' => 'app-2']);
        $firstApp = App::factory()->create(['name' => 'first', 'node_id' => $firstNode->id]);
        $secondApp = App::factory()->create(['name' => 'second', 'node_id' => $secondNode->id]);

        Workspace::factory()->create(['app_id' => $firstApp->id]);
        Workspace::factory()->create(['app_id' => $secondApp->id]);

        $response = $this->call('GET', '/api/workspaces', [], [], [], ['REMOTE_ADDR' => WORKSPACE_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(2, 'success.data.workspaces');
    });

    it('does not treat an unassigned caller as gateway visibility', function (): void {
        createWorkspaceListCallerNode();
        $node = createWorkspaceListAppNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $response = $this->call('GET', '/api/workspaces', [], [], [], ['REMOTE_ADDR' => WORKSPACE_LIST_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('returns authorization failure when the caller has no workspace registry visibility', function (): void {
        createWorkspaceListCallerNode();
        $node = createWorkspaceListAppNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $response = $this->call('GET', '/api/workspaces', [], [], [], ['REMOTE_ADDR' => WORKSPACE_LIST_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This node is not authorized to read the workspace registry.')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'workspace:read');
    });

    it('returns validation errors for unknown filters', function (string $query, string $field, string $value, string $message): void {
        $caller = createWorkspaceListCallerNode();
        assignWorkspaceListGatewayRole($caller);

        $response = $this->call('GET', "/api/workspaces?{$query}", [], [], [], ['REMOTE_ADDR' => WORKSPACE_LIST_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', $message)
            ->assertJsonPath('error.meta.field', $field)
            ->assertJsonPath('error.meta.value', $value);
    })->with([
        'unknown app' => ['app=unknown-app', 'app', 'unknown-app', "Unknown app: 'unknown-app'."],
        'unknown node' => ['node=unknown-node', 'node', 'unknown-node', "Unknown node: 'unknown-node'."],
        'multi app' => ['app=docs,site', 'app', 'docs,site', "Unknown app: 'docs,site'."],
    ]);

    it('returns the workspace list entity shape', function (): void {
        $caller = createWorkspaceListCallerNode();
        assignWorkspaceListGatewayRole($caller);
        $node = createWorkspaceListAppNode(['name' => 'app-1', 'tld' => 'test']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => null]);

        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        $response = $this->call('GET', '/api/workspaces', [], [], [], ['REMOTE_ADDR' => WORKSPACE_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.workspaces.0', [
                'name' => 'feature-docs',
                'app' => 'docs',
                'node' => 'app-1',
                'url' => 'https://feature-docs.docs.test',
                'lifecycle_status' => 'setup-pending',
            ]);
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/workspaces');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
