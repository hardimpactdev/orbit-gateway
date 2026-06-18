<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('DatabaseConnection models', function (): void {
    it('encrypts credentials when persisted', function (): void {
        $connection = DatabaseConnection::create([
            'slug' => 'primary',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'orbit',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);

        $storedCredentials = DB::table('database_connections')
            ->where('id', $connection->id)
            ->value('credentials');
        $jsonCredentials = json_encode(['password' => 'secret'], JSON_THROW_ON_ERROR);

        expect($storedCredentials)->toBeString()
            ->not->toBe('secret')
            ->and($storedCredentials)->not->toBe($jsonCredentials)
            ->and($connection->fresh()->credentials)->toBe(['password' => 'secret']);
    });

    it('relates an app target to its connection and owning app', function (): void {
        $app = App::factory()->create();
        $connection = DatabaseConnection::factory()->create();

        $target = DatabaseConnectionTarget::factory()
            ->for($connection, 'connection')
            ->forApp($app)
            ->create(['env_prefix' => 'DB']);

        expect($target->connection->is($connection))->toBeTrue()
            ->and($target->app->is($app))->toBeTrue()
            ->and($target->workspace)->toBeNull();
    });

    it('relates a workspace target to its connection and owning workspace', function (): void {
        $workspace = Workspace::factory()->create();
        $connection = DatabaseConnection::factory()->create();

        $target = DatabaseConnectionTarget::factory()
            ->for($connection, 'connection')
            ->forWorkspace($workspace)
            ->create(['env_prefix' => 'DB']);

        expect($target->connection->is($connection))->toBeTrue()
            ->and($target->workspace->is($workspace))->toBeTrue()
            ->and($target->app)->toBeNull();
    });

    it('maps app database connections through its target rows', function (): void {
        $app = App::factory()->create();
        $primary = DatabaseConnection::factory()->create(['slug' => 'app-primary']);
        $analytics = DatabaseConnection::factory()->create(['slug' => 'app-analytics']);

        DatabaseConnectionTarget::factory()->for($primary, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);
        DatabaseConnectionTarget::factory()->for($analytics, 'connection')->forApp($app)->create(['env_prefix' => 'ANALYTICS_DB']);

        expect($app->databaseConnectionTargets)->toHaveCount(2)
            ->and($app->databaseConnections->modelKeys())->toEqualCanonicalizing([$primary->id, $analytics->id]);
    });

    it('maps workspace database connections through its target rows', function (): void {
        $workspace = Workspace::factory()->create();
        $primary = DatabaseConnection::factory()->create(['slug' => 'workspace-primary']);
        $analytics = DatabaseConnection::factory()->create(['slug' => 'workspace-analytics']);

        DatabaseConnectionTarget::factory()->for($primary, 'connection')->forWorkspace($workspace)->create(['env_prefix' => 'DB']);
        DatabaseConnectionTarget::factory()->for($analytics, 'connection')->forWorkspace($workspace)->create(['env_prefix' => 'ANALYTICS_DB']);

        expect($workspace->databaseConnectionTargets)->toHaveCount(2)
            ->and($workspace->databaseConnections->modelKeys())->toEqualCanonicalizing([$primary->id, $analytics->id]);
    });

    it('keeps slugs globally unique', function (): void {
        DatabaseConnection::factory()->create(['slug' => 'shared-slug']);

        expect(fn () => DatabaseConnection::create([
            'slug' => 'shared-slug',
            'driver' => 'mysql',
        ]))->toThrow(QueryException::class);
    });
});
