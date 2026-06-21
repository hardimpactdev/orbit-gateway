<?php

declare(strict_types=1);

use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_SHOW_CALLER_WG_IP = '10.6.0.96';

function createWorkspaceShowCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => WORKSPACE_SHOW_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_SHOW_CALLER_WG_IP,
    ], $overrides));
}

function grantWorkspaceShowAccess(Node $caller, Node $appNode): void
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

function assignWorkspaceShowRole(Node $node, string $role = 'app-dev'): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $role === 'app-dev' ? ['tld' => 'test'] : [],
    ]);
}

describe('WorkspaceShowController', function (): void {
    it('returns registry details for a visible workspace', function (): void {
        $caller = createWorkspaceShowCallerNode();
        $node = Node::factory()->create([
            'name' => 'app-1',
            'host' => '1.2.3.4',
            'tld' => 'test',
            'agent_ide_config' => ['adapter' => 'opencode'],
        ]);
        assignWorkspaceShowRole($node);
        grantWorkspaceShowAccess($caller, $node);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'domain' => null,
            'php_version' => '8.5',
        ]);
        $workspace = Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'path' => '/home/orbit/apps/docs/.worktrees/feature-docs',
            'agent_ide' => 'opencode',
            'agent_ide_workspace_id' => null,
        ]);

        Process::factory()->forOwner($app)->create([
            'name' => 'vite',
            'command' => 'npm run dev',
            'restart_policy' => ProcessRestartPolicy::Always,
            'crash_notification' => ProcessCrashNotification::None,
            'sort_order' => 1,
        ]);

        $response = $this->call('GET', '/api/workspaces/feature-docs?app=docs', [], [], [], ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.meta.registry_only', true)
            // canonical workspace entity
            ->assertJsonPath('success.data.workspace.name', 'feature-docs')
            ->assertJsonPath('success.data.workspace.app', 'docs')
            ->assertJsonPath('success.data.workspace.node', 'app-1')
            ->assertJsonPath('success.data.workspace.path', '/home/orbit/apps/docs/.worktrees/feature-docs')
            ->assertJsonPath('success.data.workspace.php_version', '8.5')
            ->assertJsonPath('success.data.workspace.php_inherited', true)
            ->assertJsonPath('success.data.workspace.agent_ide.adapter', 'opencode')
            ->assertJsonPath('success.data.workspace.agent_ide.workspace_id', null)
            ->assertJsonPath('success.data.workspace.adopted', false)
            ->assertJsonPath('success.data.workspace.lifecycle_status', 'expected')
            // show-only siblings
            ->assertJsonPath('success.data.node.name', 'app-1')
            ->assertJsonPath('success.data.node.host', '1.2.3.4')
            ->assertJsonPath('success.data.inherited_processes.0.name', 'vite')
            // node must be a string slug inside the entity
            ->assertJsonPath('success.data.workspace.node', 'app-1');
        $ws = $response->json('success.data.workspace');
        // absent legacy fields
        expect($ws)->not->toHaveKey('branch')
            ->and($ws)->not->toHaveKey('runtime_expectations')
            ->and($ws)->not->toHaveKey('route')
            ->and($ws)->not->toHaveKey('latest_setup_run')
            ->and($ws['agent_ide'])->not->toHaveKey('inherited_from');
    });

    it('returns ambiguous name errors when app is omitted', function (): void {
        $gateway = createWorkspaceShowCallerNode();
        assignWorkspaceShowRole($gateway, 'gateway');
        $firstNode = Node::factory()->create(['name' => 'app-1']);
        $secondNode = Node::factory()->create(['name' => 'app-2']);
        assignWorkspaceShowRole($firstNode);
        assignWorkspaceShowRole($secondNode);
        $docs = App::factory()->create(['name' => 'docs', 'node_id' => $firstNode->id]);
        $api = App::factory()->create(['name' => 'api', 'node_id' => $secondNode->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $docs->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $api->id]);

        $response = $this->call('GET', '/api/workspaces/feature-docs', [], [], [], ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'workspace.ambiguous_name')
            ->assertJsonPath('error.meta.name', 'feature-docs')
            ->assertJsonPath('error.meta.apps', ['docs', 'api']);
    });

    it('resolves a visible workspace by path prefix', function (): void {
        $caller = createWorkspaceShowCallerNode();
        $node = Node::factory()->create(['name' => 'app-1']);
        assignWorkspaceShowRole($node);
        grantWorkspaceShowAccess($caller, $node);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'path' => '/srv/docs/.worktrees/feature-docs',
        ]);

        $response = $this->call('GET', '/api/workspaces/resolve-by-path?path=/srv/docs/.worktrees/feature-docs/app', [], [], [], ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.workspace.name', 'feature-docs')
            ->assertJsonPath('success.meta.registry_only', true);
    });

    it('returns not found for hidden workspaces', function (): void {
        createWorkspaceShowCallerNode();
        $node = Node::factory()->create();
        assignWorkspaceShowRole($node);
        $app = App::factory()->create(['node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'hidden', 'app_id' => $app->id]);

        $response = $this->call('GET', '/api/workspaces/hidden', [], [], [], ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('does not grant fleet-wide visibility to unassigned callers', function (): void {
        createWorkspaceShowCallerNode();
        $node = Node::factory()->create();
        assignWorkspaceShowRole($node);
        $app = App::factory()->create(['node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'hidden', 'app_id' => $app->id]);

        $response = $this->call('GET', '/api/workspaces/hidden', [], [], [], ['REMOTE_ADDR' => WORKSPACE_SHOW_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'workspace:read');
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/workspaces/feature-docs');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
