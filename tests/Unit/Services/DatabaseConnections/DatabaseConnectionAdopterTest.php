<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseConnectionAdopter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('DatabaseConnectionAdopter', function (): void {
    it('materializes a connection and target for an existing app env and encrypts the password', function (): void {
        $node = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/database-adopter-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $results = app(DatabaseConnectionAdopter::class)->adopt($node);

        $connection = DatabaseConnection::query()->where('slug', 'docs')->first();

        expect($results)->toHaveCount(1)
            ->and($connection)->not->toBeNull()
            ->and($connection?->credentials)->toMatchArray(['password' => 'secret'])
            ->and(DB::table('database_connections')->where('id', $connection?->id)->value('credentials'))->not->toBe(json_encode(['password' => 'secret']))
            ->and($connection?->targets()->first()?->env_prefix)->toBe('DB');
    });

    it('materializes a workspace connection with the workspace-app slug', function (): void {
        $node = Node::factory()->appDev()->create(['status' => 'active']);
        $app = App::factory()->create(['node_id' => $node->id, 'name' => 'docs']);
        Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '/srv/docs/.worktrees/feature',
        ]);

        app()->instance(RemoteShell::class, new DatabaseConnectionAdopterRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=feature_docs\nDB_USERNAME=feature\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
        ]));

        $results = app(DatabaseConnectionAdopter::class)->adopt($node);

        expect($results)->toHaveCount(1)
            ->and(DatabaseConnection::query()->where('slug', 'feature-docs')->exists())->toBeTrue();
    });

    it('normalizes adopted app and workspace slugs from human names', function (): void {
        $node = Node::factory()->gateway()->create(['status' => 'active']);
        $appPath = storage_path('framework/testing/database-adopter-normalized-app');
        File::ensureDirectoryExists($appPath);
        File::put($appPath.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\nANALYTICS_DB_CONNECTION=mysql\nANALYTICS_DB_HOST=analytics.internal\nANALYTICS_DB_PORT=3306\nANALYTICS_DB_DATABASE=analytics\nANALYTICS_DB_USERNAME=analytics\nANALYTICS_DB_PASSWORD=top-secret\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'Docs API!',
            'path' => $appPath,
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'Feature Branch #1',
            'path' => storage_path('framework/testing/database-adopter-normalized-workspace'),
        ]);
        File::ensureDirectoryExists($workspace->path);
        File::put($workspace->path.'/.env', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=feature_docs\nDB_USERNAME=feature\nDB_PASSWORD=secret\n");

        app(DatabaseConnectionAdopter::class)->adopt($node);

        expect(DatabaseConnection::query()->where('slug', 'docs-api')->exists())->toBeTrue()
            ->and(DatabaseConnection::query()->where('slug', 'docs-api-analytics-db')->exists())->toBeTrue()
            ->and(DatabaseConnection::query()->where('slug', 'feature-branch-1-docs-api')->exists())->toBeTrue();
    });

    it('reuses an existing app target connection instead of creating slug duplicates on rerun', function (): void {
        $node = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/database-adopter-idempotent-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\nDB_HOST=new-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=new-user\nDB_PASSWORD=new-secret\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'old-host',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'old-user',
            'credentials' => ['password' => 'old-secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        $results = app(DatabaseConnectionAdopter::class)->adopt($node);

        $connection->refresh();

        expect($results)->toHaveCount(1)
            ->and(DatabaseConnection::query()->count())->toBe(1)
            ->and(DatabaseConnection::query()->where('slug', 'docs-2')->exists())->toBeFalse()
            ->and($connection)->toMatchArray([
                'driver' => 'mysql',
                'host' => 'new-host',
                'port' => 3306,
                'database' => 'docs_v2',
                'username' => 'new-user',
            ])
            ->and($connection->credentials)->toMatchArray(['password' => 'new-secret']);
    });

    it('adopts both DB and ANALYTICS_DB prefixes for the same app', function (): void {
        $node = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/database-adopter-multi-prefix-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', <<<'ENV'
DB_CONNECTION=pgsql
DB_HOST=db.internal
DB_PORT=5432
DB_DATABASE=docs
DB_USERNAME=orbit
DB_PASSWORD=secret
ANALYTICS_DB_CONNECTION=mysql
ANALYTICS_DB_HOST=analytics.internal
ANALYTICS_DB_PORT=3306
ANALYTICS_DB_DATABASE=analytics
ANALYTICS_DB_USERNAME=analytics
ANALYTICS_DB_PASSWORD=top-secret
ENV);

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $results = app(DatabaseConnectionAdopter::class)->adopt($node);

        expect($results)->toHaveCount(2)
            ->and($app->databaseConnectionTargets()->pluck('env_prefix')->sort()->values()->all())->toBe(['ANALYTICS_DB', 'DB'])
            ->and(DatabaseConnection::query()->where('slug', 'docs')->exists())->toBeTrue()
            ->and(DatabaseConnection::query()->where('slug', 'docs-analytics-db')->exists())->toBeTrue();
    });

    it('adopts custom complete prefixes for the same app', function (): void {
        $node = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/database-adopter-custom-prefix-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "REPORTING_DB_CONNECTION=pgsql\nREPORTING_DB_HOST=reporting.internal\nREPORTING_DB_PORT=5432\nREPORTING_DB_DATABASE=reporting\nREPORTING_DB_USERNAME=reporting\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $results = app(DatabaseConnectionAdopter::class)->adopt($node);

        expect($results)->toHaveCount(1)
            ->and($app->databaseConnectionTargets()->pluck('env_prefix')->all())->toBe(['REPORTING_DB'])
            ->and(DatabaseConnection::query()->where('slug', 'docs-reporting-db')->exists())->toBeTrue();
    });

    it('does not adopt partial mapped env payloads', function (): void {
        $node = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/database-adopter-partial-update');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_PORT=6432\nDB_USERNAME=partial-user\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'stored-host',
            'port' => 5432,
            'database' => 'stored_db',
            'username' => 'stored-user',
            'credentials' => ['password' => 'stored-secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        $results = app(DatabaseConnectionAdopter::class)->adopt($node);

        $connection->refresh();

        expect($results)->toBe([])
            ->and($connection)->toMatchArray([
                'driver' => 'pgsql',
                'host' => 'stored-host',
                'port' => 5432,
                'database' => 'stored_db',
                'username' => 'stored-user',
            ])
            ->and($connection->credentials)->toMatchArray(['password' => 'stored-secret']);
    });

    it('does not create a new connection from an otherwise empty env group', function (): void {
        $node = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/database-adopter-empty-group');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $results = app(DatabaseConnectionAdopter::class)->adopt($node);

        expect($results)->toBe([])
            ->and(DatabaseConnection::query()->count())->toBe(0)
            ->and(DatabaseConnectionTarget::query()->count())->toBe(0);
    });

    it('stores adopted sqlite database values as path only', function (): void {
        $node = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/database-adopter-sqlite');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=/srv/docs/database/database.sqlite\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $results = app(DatabaseConnectionAdopter::class)->adopt($node);
        $connection = DatabaseConnection::query()->where('slug', 'docs')->first();

        expect($results)->toHaveCount(1)
            ->and($connection)->not->toBeNull()
            ->and($connection?->driver)->toBe('sqlite')
            ->and($connection?->database)->toBeNull()
            ->and($connection?->path)->toBe('/srv/docs/database/database.sqlite')
            ->and($connection?->node_id)->toBe($node->id);
    });

    it('does not reuse sqlite connections owned by another node', function (): void {
        $node = Node::factory()->gateway()->create(['status' => 'active']);
        $otherNode = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/database-adopter-sqlite-node');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=/srv/docs/database/database.sqlite\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        DatabaseConnection::factory()->create([
            'node_id' => $otherNode->id,
            'slug' => 'other-docs',
            'driver' => 'sqlite',
            'host' => null,
            'port' => null,
            'database' => null,
            'path' => '/srv/docs/database/database.sqlite',
            'username' => null,
        ]);

        app(DatabaseConnectionAdopter::class)->adopt($node);

        $connection = DatabaseConnection::query()->where('slug', 'docs')->first();

        expect($connection)->not->toBeNull()
            ->and($connection?->node_id)->toBe($node->id)
            ->and(DatabaseConnection::query()->count())->toBe(2);
    });
});

final class DatabaseConnectionAdopterRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected remote shell call', 1);
    }
}
