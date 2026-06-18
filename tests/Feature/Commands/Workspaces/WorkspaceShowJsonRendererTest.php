<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WS_SHOW_JSON_CALLER_WG_IP = '10.6.0.97';

function wsShowJsonCallerNode(): Node
{
    return Node::factory()->create([
        'name' => 'caller',
        'host' => WS_SHOW_JSON_CALLER_WG_IP,
        'wireguard_address' => WS_SHOW_JSON_CALLER_WG_IP,
    ]);
}

function wsShowJsonAppNode(string $name = 'app-1', string $host = '1.2.3.4'): Node
{
    $node = Node::factory()->create([
        'name' => $name,
        'host' => $host,
        'tld' => 'test',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

function wsShowJsonGrantAccess(Node $caller, Node $appNode): void
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

// ---------------------------------------------------------------------------
// Success envelope shape
// ---------------------------------------------------------------------------

describe('WorkspaceShowJsonRenderer success shape', function (): void {
    it('returns the canonical workspace entity under success.data.workspace', function (): void {
        $caller = wsShowJsonCallerNode();
        $node = wsShowJsonAppNode();
        wsShowJsonGrantAccess($caller, $node);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'php_version' => '8.5',
        ]);
        $workspace = Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'path' => '/home/orbit/apps/docs/.worktrees/feature-docs',
            'php_version' => null,
            'agent_ide' => 'opencode',
            'agent_ide_workspace_id' => null,
        ]);

        $response = $this->call('GET', '/api/workspaces/feature-docs?app=docs', [], [], [], ['REMOTE_ADDR' => WS_SHOW_JSON_CALLER_WG_IP]);

        $response->assertOk();

        $ws = $response->json('success.data.workspace');

        expect($ws)->toBeArray()
            ->and($ws['name'])->toBe('feature-docs')
            ->and($ws['app'])->toBe('docs')
            ->and($ws['node'])->toBe('app-1')
            ->and($ws['path'])->toBe('/home/orbit/apps/docs/.worktrees/feature-docs')
            ->and($ws['php_version'])->toBe('8.5')
            ->and($ws['php_inherited'])->toBeTrue()
            ->and($ws['agent_ide'])->toBeArray()
            ->and($ws['agent_ide']['adapter'])->toBe('opencode')
            ->and($ws['agent_ide']['workspace_id'])->toBeNull()
            ->and($ws['adopted'])->toBeFalse()
            ->and($ws['lifecycle_status'])->toBe('expected');
    });

    it('returns the node sibling with name and host', function (): void {
        $caller = wsShowJsonCallerNode();
        $node = wsShowJsonAppNode('app-1', '1.2.3.4');
        wsShowJsonGrantAccess($caller, $node);

        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $response = $this->call('GET', '/api/workspaces/feature-docs?app=docs', [], [], [], ['REMOTE_ADDR' => WS_SHOW_JSON_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.name', 'app-1')
            ->assertJsonPath('success.data.node.host', '1.2.3.4');
    });

    it('returns inherited_processes as sibling with process slugs only', function (): void {
        $caller = wsShowJsonCallerNode();
        $node = wsShowJsonAppNode();
        wsShowJsonGrantAccess($caller, $node);

        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        Process::factory()->forOwner($app)->create(['name' => 'vite', 'sort_order' => 1]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'sort_order' => 2]);

        $response = $this->call('GET', '/api/workspaces/feature-docs?app=docs', [], [], [], ['REMOTE_ADDR' => WS_SHOW_JSON_CALLER_WG_IP]);

        $response->assertOk();

        $processes = $response->json('success.data.inherited_processes');

        expect($processes)->toBeArray()
            ->and($processes)->toHaveCount(2)
            ->and($processes[0])->toBe(['name' => 'vite'])
            ->and($processes[1])->toBe(['name' => 'queue']);
    });

    it('returns an empty inherited_processes list when the app has no processes', function (): void {
        $caller = wsShowJsonCallerNode();
        $node = wsShowJsonAppNode();
        wsShowJsonGrantAccess($caller, $node);

        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $response = $this->call('GET', '/api/workspaces/feature-docs?app=docs', [], [], [], ['REMOTE_ADDR' => WS_SHOW_JSON_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.inherited_processes', []);
    });

    it('sets meta.registry_only = true', function (): void {
        $caller = wsShowJsonCallerNode();
        $node = wsShowJsonAppNode();
        wsShowJsonGrantAccess($caller, $node);

        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $response = $this->call('GET', '/api/workspaces/feature-docs?app=docs', [], [], [], ['REMOTE_ADDR' => WS_SHOW_JSON_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.meta.registry_only', true);
    });

    it('sets php_inherited=false when workspace has explicit php_version override', function (): void {
        $caller = wsShowJsonCallerNode();
        $node = wsShowJsonAppNode();
        wsShowJsonGrantAccess($caller, $node);

        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.4']);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'php_version' => '8.5',
        ]);

        $response = $this->call('GET', '/api/workspaces/feature-docs?app=docs', [], [], [], ['REMOTE_ADDR' => WS_SHOW_JSON_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.workspace.php_version', '8.5')
            ->assertJsonPath('success.data.workspace.php_inherited', false);
    });

    it('node inside workspace entity is a slug string, not an object', function (): void {
        $caller = wsShowJsonCallerNode();
        $node = wsShowJsonAppNode();
        wsShowJsonGrantAccess($caller, $node);

        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $response = $this->call('GET', '/api/workspaces/feature-docs?app=docs', [], [], [], ['REMOTE_ADDR' => WS_SHOW_JSON_CALLER_WG_IP]);

        $response->assertOk();

        expect($response->json('success.data.workspace.node'))->toBeString();
    });

    it('does not include branch, runtime_expectations, route, or latest_setup_run', function (): void {
        $caller = wsShowJsonCallerNode();
        $node = wsShowJsonAppNode();
        wsShowJsonGrantAccess($caller, $node);

        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $response = $this->call('GET', '/api/workspaces/feature-docs?app=docs', [], [], [], ['REMOTE_ADDR' => WS_SHOW_JSON_CALLER_WG_IP]);

        $response->assertOk();

        $ws = $response->json('success.data.workspace');

        expect($ws)->not->toHaveKey('branch')
            ->and($ws)->not->toHaveKey('runtime_expectations')
            ->and($ws)->not->toHaveKey('route')
            ->and($ws)->not->toHaveKey('latest_setup_run');
    });

    it('does not include agent_ide.inherited_from or agent_ide.workspace_discovery', function (): void {
        $caller = wsShowJsonCallerNode();
        $node = wsShowJsonAppNode();
        wsShowJsonGrantAccess($caller, $node);

        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'agent_ide' => 'opencode']);

        $response = $this->call('GET', '/api/workspaces/feature-docs?app=docs', [], [], [], ['REMOTE_ADDR' => WS_SHOW_JSON_CALLER_WG_IP]);

        $response->assertOk();

        $agentIde = $response->json('success.data.workspace.agent_ide');

        expect($agentIde)->not->toHaveKey('inherited_from')
            ->and($agentIde)->not->toHaveKey('workspace_discovery')
            ->and($agentIde)->toHaveKey('adapter')
            ->and($agentIde)->toHaveKey('workspace_id');
    });
});

// ---------------------------------------------------------------------------
// Error codes
// ---------------------------------------------------------------------------

describe('WorkspaceShowJsonRenderer error codes', function (): void {
    it('returns workspace.not_found when workspace does not exist', function (): void {
        $caller = wsShowJsonCallerNode();
        $node = wsShowJsonAppNode();
        wsShowJsonGrantAccess($caller, $node);

        $response = $this->call('GET', '/api/workspaces/no-such-workspace?app=docs', [], [], [], ['REMOTE_ADDR' => WS_SHOW_JSON_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'workspace.not_found')
            ->assertJsonPath('error.meta.name', 'no-such-workspace');
    });

    it('returns workspace.ambiguous_name when name matches multiple apps', function (): void {
        $caller = wsShowJsonCallerNode();
        $nodeA = wsShowJsonAppNode('app-1', '1.2.3.4');
        wsShowJsonGrantAccess($caller, $nodeA);

        $nodeB = Node::factory()->create(['name' => 'app-2', 'host' => '1.2.3.5']);
        NodeRoleAssignment::factory()->create(['node_id' => $nodeB->id, 'role' => 'app-dev', 'status' => 'active', 'settings' => ['tld' => 'test']]);
        wsShowJsonGrantAccess($caller, $nodeB);

        $appA = App::factory()->create(['name' => 'docs', 'node_id' => $nodeA->id]);
        $appB = App::factory()->create(['name' => 'api', 'node_id' => $nodeB->id]);
        Workspace::factory()->create(['name' => 'feature-x', 'app_id' => $appA->id]);
        Workspace::factory()->create(['name' => 'feature-x', 'app_id' => $appB->id]);

        $response = $this->call('GET', '/api/workspaces/feature-x', [], [], [], ['REMOTE_ADDR' => WS_SHOW_JSON_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'workspace.ambiguous_name')
            ->assertJsonPath('error.meta.name', 'feature-x');

        expect($response->json('error.meta.apps'))->toContain('docs')->toContain('api');
    });

    it('returns authorization_failed when caller lacks workspace:read permission', function (): void {
        wsShowJsonCallerNode();
        $node = wsShowJsonAppNode();

        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $response = $this->call('GET', '/api/workspaces/feature-docs?app=docs', [], [], [], ['REMOTE_ADDR' => WS_SHOW_JSON_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'workspace:read');
    });

    it('returns authorization_failed with peer identity unknown for unauthenticated callers', function (): void {
        $response = $this->getJson('/api/workspaces/feature-docs?app=docs');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
