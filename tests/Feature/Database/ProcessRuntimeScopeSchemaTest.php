<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('uses polymorphic process ownership instead of app or workspace columns', function (): void {
    expect(Schema::hasColumn('processes', 'owner_type'))->toBeTrue()
        ->and(Schema::hasColumn('processes', 'owner_id'))->toBeTrue()
        ->and(Schema::hasColumn('processes', 'node_id'))->toBeTrue()
        ->and(Schema::hasColumn('processes', 'app_id'))->toBeFalse()
        ->and(Schema::hasColumn('processes', 'workspace_id'))->toBeFalse();
});

it('stores node owned process runtime configuration', function (): void {
    $node = Node::factory()->create(['name' => 'app-1']);

    $process = $node->processes()->create([
        'node_id' => $node->id,
        'name' => 'mysql8',
        'command' => 'mysqld',
        'runtime' => ProcessRuntime::Docker,
        'tool' => null,
        'runtime_config' => [
            'definition' => 'mysql',
            'version' => '8.4',
            'image' => 'mysql:8.4',
            'ports' => ['3306:3306'],
            'volumes' => ['mysql8-data:/var/lib/mysql'],
        ],
        'sort_order' => 1,
    ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(Node::class)
        ->node_id->toBe($node->id)
        ->tool->toBeNull()
        ->runtime_config->toBe([
            'definition' => 'mysql',
            'version' => '8.4',
            'image' => 'mysql:8.4',
            'ports' => ['3306:3306'],
            'volumes' => ['mysql8-data:/var/lib/mysql'],
        ]);
});

it('stores node owned systemd process runtime configuration with a tool dependency', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);

    $process = $node->processes()->create([
        'node_id' => $node->id,
        'name' => 'opencode-server',
        'command' => 'opencode serve -a',
        'runtime' => ProcessRuntime::Systemd,
        'tool' => 'opencode',
        'runtime_config' => [
            'service' => 'opencode-server',
        ],
        'sort_order' => 1,
    ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(Node::class)
        ->node_id->toBe($node->id)
        ->runtime->toBe(ProcessRuntime::Systemd)
        ->tool->toBe('opencode')
        ->runtime_config->toBe([
            'service' => 'opencode-server',
        ]);
});

it('stores role owned process runtime configuration', function (): void {
    $node = Node::factory()->create(['name' => 'database-1']);
    $role = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'database',
    ]);

    $process = $role->processes()->create([
        'node_id' => $node->id,
        'name' => 'postgres16',
        'command' => 'postgres',
        'runtime' => ProcessRuntime::Docker,
        'tool' => null,
        'runtime_config' => [
            'definition' => 'postgres',
            'version' => '16',
            'image' => 'postgres:16',
        ],
        'sort_order' => 1,
    ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(NodeRoleAssignment::class)
        ->node_id->toBe($node->id)
        ->tool->toBeNull()
        ->runtime_config->toBe([
            'definition' => 'postgres',
            'version' => '16',
            'image' => 'postgres:16',
        ]);
});

it('stores app owned process runtime configuration', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['node_id' => $node->id, 'name' => 'abc']);

    $process = $app->processes()->create([
        'node_id' => $node->id,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'runtime' => ProcessRuntime::Systemd,
        'tool' => 'php-cli',
        'runtime_config' => [
            'directory' => '/home/orbit/apps/abc',
        ],
        'sort_order' => 1,
    ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(App::class)
        ->node_id->toBe($node->id)
        ->tool->toBe('php-cli')
        ->runtime_config->toBe([
            'directory' => '/home/orbit/apps/abc',
        ]);
});

it('stores workspace owned process runtime configuration', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['node_id' => $node->id, 'name' => 'abc']);
    $workspace = Workspace::factory()->create(['app_id' => $app->id, 'name' => 'redesign']);

    $process = $workspace->processes()->create([
        'node_id' => $node->id,
        'name' => 'horizon-redesign',
        'runtime' => ProcessRuntime::Systemd,
        'tool' => 'php-cli',
        'command' => 'php artisan horizon',
        'runtime_config' => [
            'directory' => '/home/orbit/apps/abc/worktrees/redesign',
        ],
        'sort_order' => 1,
    ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(Workspace::class)
        ->node_id->toBe($node->id)
        ->tool->toBe('php-cli')
        ->runtime_config->toBe([
            'directory' => '/home/orbit/apps/abc/worktrees/redesign',
        ]);
});

it('defaults app and workspace host command processes to systemd when runtime is omitted', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['node_id' => $node->id, 'name' => 'abc']);
    $workspace = Workspace::factory()->create(['app_id' => $app->id, 'name' => 'redesign']);

    $relationProcess = $app->processes()->create([
        'node_id' => $node->id,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'sort_order' => 1,
    ]);

    $factoryProcess = Process::factory()->forOwner($workspace)->create([
        'name' => 'horizon-redesign',
    ]);

    DB::table('processes')->insert([
        'node_id' => $node->id,
        'owner_type' => $workspace->getMorphClass(),
        'owner_id' => $workspace->id,
        'name' => 'vite-redesign',
        'command' => 'npm run dev',
        'sort_order' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($relationProcess->refresh()->runtime)->toBe(ProcessRuntime::Systemd)
        ->and($factoryProcess->refresh()->runtime)->toBe(ProcessRuntime::Systemd)
        ->and(DB::table('processes')->where('name', 'vite-redesign')->value('runtime'))->toBe(ProcessRuntime::Systemd->value);
});
