<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_HISTORY_CALLER_WG_IP = '10.6.0.95';

function createWorkspaceHistoryCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => WORKSPACE_HISTORY_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_HISTORY_CALLER_WG_IP], $overrides);

    if ($role === 'gateway') {
        return createTestGatewayNode($attributes);
    }

    return Node::factory()->create($attributes);
}

function grantWorkspaceHistoryAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['workspace:history'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now()]);
}

describe('WorkspaceHistoryController', function (): void {
    it('returns visible workspace runs sorted newest first with pagination metadata', function (): void {
        $caller = createWorkspaceHistoryCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantWorkspaceHistoryAccess($caller, $node);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        WorkspaceRun::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => 'failed',
            'started_at' => '2026-05-01 10:00:00',
            'completed_at' => '2026-05-01 10:01:00']);
        WorkspaceRun::factory()->create([
            'workspace_id' => $workspace->id,
            'status' => 'completed',
            'started_at' => '2026-05-02 10:00:00',
            'completed_at' => '2026-05-02 10:02:00']);

        $response = $this->call('GET', '/api/workspaces/feature-docs/history?app=docs&limit=1', [], [], [], ['REMOTE_ADDR' => WORKSPACE_HISTORY_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.runs')
            ->assertJsonPath('success.data.runs.0.status', 'completed')
            ->assertJsonPath('success.meta.pagination.total', 2)
            ->assertJsonPath('success.meta.pagination.limit', 1)
            ->assertJsonPath('success.meta.pagination.limit_capped', false);
    });

    it('caps limit at 500 and reports the cap', function (): void {
        createWorkspaceHistoryCallerNode(role: 'gateway');
        $node = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $response = $this->call('GET', '/api/workspaces/feature-docs/history?limit=900', [], [], [], ['REMOTE_ADDR' => WORKSPACE_HISTORY_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.meta.pagination.limit', 500)
            ->assertJsonPath('success.meta.pagination.limit_capped', true);
    });

    it('resolves history by path for forwarded cwd calls', function (): void {
        $caller = createWorkspaceHistoryCallerNode();
        $node = createTestAppHostNode();
        grantWorkspaceHistoryAccess($caller, $node);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $workspace = Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'path' => '/srv/docs/.worktrees/feature-docs']);
        WorkspaceRun::factory()->create(['workspace_id' => $workspace->id, 'started_at' => '2026-05-02 10:00:00']);

        $response = $this->call('GET', '/api/workspaces/history/resolve-by-path?path=/srv/docs/.worktrees/feature-docs/app', [], [], [], ['REMOTE_ADDR' => WORKSPACE_HISTORY_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.runs.0.workspace', 'feature-docs');
    });

    it('returns validation errors for invalid filters', function (): void {
        createWorkspaceHistoryCallerNode(role: 'gateway');

        $response = $this->call('GET', '/api/workspaces/feature-docs/history?limit=0', [], [], [], ['REMOTE_ADDR' => WORKSPACE_HISTORY_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'limit');
    });

    it('returns authorization failure when the caller has no workspace visibility', function (): void {
        createWorkspaceHistoryCallerNode();
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $response = $this->call('GET', '/api/workspaces/feature-docs/history', [], [], [], ['REMOTE_ADDR' => WORKSPACE_HISTORY_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'workspace:history');
    });
});
