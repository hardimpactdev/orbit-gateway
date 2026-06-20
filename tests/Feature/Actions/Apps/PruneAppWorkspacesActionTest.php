<?php

declare(strict_types=1);

use App\Actions\Apps\PruneAppWorkspaces;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('nodes')->insert([
        [
            'name' => 'gateway',
            'host' => 'gateway',
            'orbit_path' => '/home/gateway/orbit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => 1,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    DB::table('apps')->insert([
        [
            'name' => 'demo',
            'domain' => 'demo.beast',
            'node_id' => 1,
            'path' => '/home/nckrtl/apps/demo',
            'php_version' => '8.5',
            'document_root' => 'public',
            'agent_ide_config' => json_encode(['adapter' => 'opencode']),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    app()->instance(AgentIdeMessageAdapter::class, new PruneAppActionTestAdapter);
    app()->instance(RemoteShell::class, new PruneAppWorkspacesActionRemoteShell);
});

it('identifies stale workspaces', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'stale-ws',
        'path' => '/home/nckrtl/apps/demo/stale-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    Workspace::create([
        'app_id' => 1,
        'name' => 'active-ws',
        'path' => '/home/nckrtl/apps/demo/active-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
        'agent_ide_workspace_id' => 'sess_123',
    ]);

    $app = App::query()->with('node')->first();
    $prune = app(PruneAppWorkspaces::class);
    $result = $prune->handle($app);

    expect($result['app'])->toBe('demo');
    expect($result['stale_workspaces'])->toHaveCount(1);
    expect($result['stale_workspaces'][0]['name'])->toBe('stale-ws');
    expect($result['stale_workspaces'][0]['removed'])->toBeTrue();
    expect($result['dry_run'])->toBeFalse();
});

it('dry-run does not remove workspaces', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'stale-ws',
        'path' => '/home/nckrtl/apps/demo/stale-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    $app = App::query()->with('node')->first();
    $prune = app(PruneAppWorkspaces::class);
    $result = $prune->handle($app, dryRun: true);

    expect($result['dry_run'])->toBeTrue();
    expect($result['stale_workspaces'][0]['removed'])->toBeFalse();
    expect(Workspace::query()->where('name', 'stale-ws')->exists())->toBeTrue();
});

it('returns empty when no stale workspaces', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'active-ws',
        'path' => '/home/nckrtl/apps/demo/active-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
        'agent_ide_workspace_id' => 'sess_123',
    ]);

    $app = App::query()->with('node')->first();
    $prune = app(PruneAppWorkspaces::class);
    $result = $prune->handle($app);

    expect($result['stale_workspaces'])->toBe([]);
});

it('throws when no adapter configured', function (): void {
    App::query()->update(['agent_ide_config' => null]);

    $app = App::query()->with('node')->first();
    $prune = app(PruneAppWorkspaces::class);

    expect(fn () => $prune->handle($app))
        ->toThrow(RuntimeException::class, 'No agent IDE adapter configured for this app.');
});

it('prunes using explicit adapter name', function (): void {
    Workspace::create([
        'app_id' => 1,
        'name' => 'stale-ws',
        'path' => '/home/nckrtl/apps/demo/stale-ws',
        'lifecycle_status' => WorkspaceLifecycleStatus::Active,
    ]);

    $app = App::query()->with('node')->first();
    $prune = app(PruneAppWorkspaces::class);
    $result = $prune->handle($app, adapterName: 'opencode');

    expect($result['app'])->toBe('demo');
    expect($result['stale_workspaces'])->toHaveCount(1);
    expect($result['stale_workspaces'][0]['name'])->toBe('stale-ws');
    expect($result['stale_workspaces'][0]['removed'])->toBeTrue();
});

final class PruneAppWorkspacesActionRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
