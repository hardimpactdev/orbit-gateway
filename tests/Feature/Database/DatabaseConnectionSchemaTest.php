<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the database connection tables with the expected columns and broad types', function (): void {
    expect(Schema::getColumnType('database_connections', 'slug'))->toBeIn(['string', 'varchar']);
    expect(Schema::getColumnType('database_connections', 'driver'))->toBeIn(['string', 'varchar']);
    expect(Schema::getColumnType('database_connections', 'port'))->toBeIn(['integer', 'int']);
    expect(Schema::getColumnType('database_connections', 'credentials'))->toBe('text');
    expect(Schema::getColumnType('database_connection_targets', 'env_prefix'))->toBeIn(['string', 'varchar']);

    expect(Schema::hasTable('database_connections'))->toBeTrue()
        ->and(Schema::hasColumns('database_connections', [
            'id',
            'node_id',
            'slug',
            'driver',
            'host',
            'port',
            'database',
            'path',
            'username',
            'credentials',
            'created_at',
            'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('database_connection_targets'))->toBeTrue()
        ->and(Schema::hasColumns('database_connection_targets', [
            'id',
            'database_connection_id',
            'app_id',
            'workspace_id',
            'env_prefix',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

it('enforces unique database connection slugs at the database level', function (): void {
    DB::table('database_connections')->insert([
        'slug' => 'primary',
        'driver' => 'pgsql',
        'host' => 'db.internal',
        'port' => 5432,
        'database' => 'orbit',
        'username' => 'orbit',
        'credentials' => json_encode(['password' => 'secret'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('database_connections')->insert([
        'slug' => 'primary',
        'driver' => 'mysql',
        'host' => 'mysql.internal',
        'port' => 3306,
        'database' => 'orbit',
        'username' => 'orbit',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces env prefix uniqueness per app target', function (): void {
    $app = App::factory()->create();

    $firstConnectionId = DB::table('database_connections')->insertGetId([
        'slug' => 'app-primary',
        'driver' => 'pgsql',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $secondConnectionId = DB::table('database_connections')->insertGetId([
        'slug' => 'app-analytics',
        'driver' => 'mysql',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('database_connection_targets')->insert([
        'database_connection_id' => $firstConnectionId,
        'app_id' => $app->id,
        'workspace_id' => null,
        'env_prefix' => 'DB',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('database_connection_targets')->insert([
        'database_connection_id' => $secondConnectionId,
        'app_id' => $app->id,
        'workspace_id' => null,
        'env_prefix' => 'DB',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces env prefix uniqueness per workspace target', function (): void {
    $workspace = Workspace::factory()->create();

    $firstConnectionId = DB::table('database_connections')->insertGetId([
        'slug' => 'workspace-primary',
        'driver' => 'pgsql',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $secondConnectionId = DB::table('database_connections')->insertGetId([
        'slug' => 'workspace-analytics',
        'driver' => 'mysql',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('database_connection_targets')->insert([
        'database_connection_id' => $firstConnectionId,
        'app_id' => null,
        'workspace_id' => $workspace->id,
        'env_prefix' => 'DB',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('database_connection_targets')->insert([
        'database_connection_id' => $secondConnectionId,
        'app_id' => null,
        'workspace_id' => $workspace->id,
        'env_prefix' => 'DB',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('requires each target row to belong to exactly one app or workspace', function (): void {
    $app = App::factory()->create();
    $workspace = Workspace::factory()->create();

    $connectionId = DB::table('database_connections')->insertGetId([
        'slug' => 'exclusive-target',
        'driver' => 'pgsql',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $neitherOwner = null;

    try {
        DB::table('database_connection_targets')->insert([
            'database_connection_id' => $connectionId,
            'app_id' => null,
            'workspace_id' => null,
            'env_prefix' => 'DB',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $exception) {
        $neitherOwner = $exception;
    }

    expect($neitherOwner)->toBeInstanceOf(QueryException::class)
        ->and($neitherOwner->getMessage())->toContain('database_connection_targets_owner_check');

    $bothOwners = null;

    try {
        DB::table('database_connection_targets')->insert([
            'database_connection_id' => $connectionId,
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'env_prefix' => 'ANALYTICS_DB',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $exception) {
        $bothOwners = $exception;
    }

    expect($bothOwners)->toBeInstanceOf(QueryException::class)
        ->and($bothOwners->getMessage())->toContain('database_connection_targets_owner_check');
});

it('cascades target rows when the database connection is deleted', function (): void {
    $app = App::factory()->create();

    $connectionId = DB::table('database_connections')->insertGetId([
        'slug' => 'primary',
        'driver' => 'pgsql',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('database_connection_targets')->insert([
        'database_connection_id' => $connectionId,
        'app_id' => $app->id,
        'workspace_id' => null,
        'env_prefix' => 'DB',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('database_connections')->where('id', $connectionId)->delete();

    expect(DB::table('database_connection_targets')->count())->toBe(0);
});

it('cascades target rows when the owning app or workspace is deleted', function (): void {
    $app = App::factory()->create();
    $workspace = Workspace::factory()->create(['app_id' => $app->id]);
    $node = Node::factory()->create();

    $appConnectionId = DB::table('database_connections')->insertGetId([
        'slug' => 'app-primary',
        'driver' => 'pgsql',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $workspaceConnectionId = DB::table('database_connections')->insertGetId([
        'slug' => 'workspace-sqlite',
        'node_id' => $node->id,
        'driver' => 'sqlite',
        'path' => '/srv/sqlite/app.sqlite',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('database_connection_targets')->insert([
        [
            'database_connection_id' => $appConnectionId,
            'app_id' => $app->id,
            'workspace_id' => null,
            'env_prefix' => 'DB',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'database_connection_id' => $workspaceConnectionId,
            'app_id' => null,
            'workspace_id' => $workspace->id,
            'env_prefix' => 'DB',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $workspace->delete();

    expect(DB::table('database_connection_targets')->where('database_connection_id', $workspaceConnectionId)->count())->toBe(0)
        ->and(DB::table('database_connection_targets')->where('database_connection_id', $appConnectionId)->count())->toBe(1);

    $app->delete();

    expect(DB::table('database_connection_targets')->where('database_connection_id', $appConnectionId)->count())->toBe(0);
});
