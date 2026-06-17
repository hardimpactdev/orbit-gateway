<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_LOG_CALLER_WG_IP = '10.6.0.96';

function createWorkspaceLogCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'log-caller',
        'host' => WORKSPACE_LOG_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_LOG_CALLER_WG_IP], $overrides);

    if ($role === 'gateway') {
        return createTestGatewayNode($attributes);
    }

    return Node::factory()->create($attributes);
}

function grantWorkspaceLogAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['workspace:log'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now()]);
}

function createVisibleWorkspaceLogRun(Node $appNode): WorkspaceRun
{
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
    $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
    $step = WorkspaceStep::factory()->create(['app_id' => $app->id, 'command' => 'Install dependencies']);
    $run = WorkspaceRun::factory()->create([
        'workspace_id' => $workspace->id,
        'status' => 'failed',
        'started_at' => '2026-05-02 10:00:00',
        'completed_at' => '2026-05-02 10:00:12']);
    WorkspaceRunStep::factory()->create([
        'workspace_run_id' => $run->id,
        'workspace_step_id' => $step->id,
        'command' => 'composer install',
        'exit_code' => 1,
        'output' => 'failure output',
        'started_at' => '2026-05-02 10:00:03',
        'completed_at' => '2026-05-02 10:00:11']);

    return $run;
}

describe('WorkspaceLogController', function (): void {
    it('returns captured logs for visible workspace runs', function (): void {
        $caller = createWorkspaceLogCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantWorkspaceLogAccess($caller, $node);
        $run = createVisibleWorkspaceLogRun($node);

        $response = $this->call('GET', "/api/workspaces/runs/{$run->id}/log", [], [], [], ['REMOTE_ADDR' => WORKSPACE_LOG_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.run.id', $run->id)
            ->assertJsonPath('success.data.run.workspace', 'feature-docs')
            ->assertJsonPath('success.data.run.steps.0.status', 'failure')
            ->assertJsonPath('success.meta.registry_only', true);
    });

    it('returns validation errors for invalid run ids', function (): void {
        createWorkspaceLogCallerNode(role: 'gateway');

        $response = $this->call('GET', '/api/workspaces/runs/nope/log', [], [], [], ['REMOTE_ADDR' => WORKSPACE_LOG_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'run');
    });

    it('returns run-not-found for missing runs', function (): void {
        createWorkspaceLogCallerNode(role: 'gateway');

        $response = $this->call('GET', '/api/workspaces/runs/999/log', [], [], [], ['REMOTE_ADDR' => WORKSPACE_LOG_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'workspace.run_not_found')
            ->assertJsonPath('error.meta.id', 999);
    });

    it('returns authorization failure when the caller has no workspace visibility', function (): void {
        createWorkspaceLogCallerNode();
        $node = createTestAppHostNode();
        $run = createVisibleWorkspaceLogRun($node);

        $response = $this->call('GET', "/api/workspaces/runs/{$run->id}/log", [], [], [], ['REMOTE_ADDR' => WORKSPACE_LOG_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'workspace:log');
    });
});
