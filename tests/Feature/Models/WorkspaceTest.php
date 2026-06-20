<?php

declare(strict_types=1);

use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores workspace registry intent and derives canonical fields', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'php_version' => '8.5',
    ]);

    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'feature-docs',
        'path' => '/home/orbit/apps/docs/.worktrees/feature-docs',
        'php_version' => null,
        'agent_ide' => 'opencode',
        'agent_ide_workspace_id' => 'oc_123',
        'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
    ]);

    expect($workspace->app->is($app))->toBeTrue()
        ->and($app->workspaces()->pluck('name')->all())->toBe(['feature-docs'])
        ->and($workspace->effectivePhpVersion())->toBe('8.5')
        ->and($workspace->url())->toBe('https://feature-docs.docs.test')
        ->and($workspace->lifecycle_status)->toBe(WorkspaceLifecycleStatus::SetupPending);
});

it('prefers an explicit workspace php version over the parent app version', function (): void {
    $app = App::factory()->create([
        'php_version' => '8.5',
    ]);

    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'php_version' => '8.4',
    ]);

    expect($workspace->effectivePhpVersion())->toBe('8.4');
});

it('keeps workspace names unique within a parent app only', function (): void {
    $firstApp = App::factory()->create();
    $secondApp = App::factory()->create();

    Workspace::factory()->create([
        'app_id' => $firstApp->id,
        'name' => 'feature-docs',
    ]);

    Workspace::factory()->create([
        'app_id' => $secondApp->id,
        'name' => 'feature-docs',
    ]);

    expect(Workspace::query()->where('name', 'feature-docs')->count())->toBe(2);

    expect(fn () => Workspace::factory()->create([
        'app_id' => $firstApp->id,
        'name' => 'feature-docs',
    ]))->toThrow(QueryException::class);
});

it('keeps durable run history when step definitions are removed', function (): void {
    $workspace = Workspace::factory()->create();
    $step = WorkspaceStep::factory()->create([
        'app_id' => $workspace->app_id,
    ]);
    $run = WorkspaceRun::factory()->create([
        'workspace_id' => $workspace->id,
        'phase' => WorkspaceLifecyclePhase::Setup,
        'status' => 'succeeded',
        'step_set_hash' => str_repeat('a', 64),
    ]);
    $runStep = WorkspaceRunStep::factory()->create([
        'workspace_run_id' => $run->id,
        'workspace_step_id' => $step->id,
        'command' => $step->command,
        'exit_code' => 0,
        'output' => 'ok',
    ]);

    $step->delete();
    $runStep->refresh();

    expect($workspace->runs()->first()->is($run))->toBeTrue()
        ->and($run->runSteps()->first()->is($runStep))->toBeTrue()
        ->and($run->phase)->toBe(WorkspaceLifecyclePhase::Setup)
        ->and($runStep->workspace_step_id)->toBeNull()
        ->and($runStep->step)->toBeNull();
});

it('allows proxy routes to point at workspace-owned intent', function (): void {
    $workspace = Workspace::factory()->create();
    $node = $workspace->app->node;

    $route = ProxyRoute::query()->create([
        'node_id' => $node->id,
        'domain' => 'feature-docs.docs.test',
        'app_id' => $workspace->app_id,
        'workspace_id' => $workspace->id,
        'owner_type' => 'workspace',
        'kind' => 'workspace',
        'source_hash' => str_repeat('b', 64),
    ]);

    expect($route->workspace->is($workspace))->toBeTrue()
        ->and($workspace->proxyRoutes()->first()->is($route))->toBeTrue();
});
