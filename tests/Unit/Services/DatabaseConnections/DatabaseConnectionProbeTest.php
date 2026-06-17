<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseConnectionProbe;
use App\Services\Nodes\NodeWireGuardSelfRouteProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('DatabaseConnectionProbe', function (): void {
    it('reports env missing and mismatch for an app target on a local path', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "APP_NAME=Docs\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        $issues = app(DatabaseConnectionProbe::class)->probe($node);

        expect($issues)->toHaveCount(2)
            ->and(collect($issues)->pluck('key')->all())->toBe([
                'database_connection.env_missing',
                'database_connection.env_mismatch',
            ]);
    });

    it('masks plaintext password values in mismatch details', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-secret-mismatch');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=observed-secret\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'stored-secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.env_mismatch');

        expect($issue)->not->toBeNull()
            ->and($issue['detail']['mismatched_keys']['DB_PASSWORD'] ?? null)->toBe('masked')
            ->and(json_encode($issue, JSON_THROW_ON_ERROR))->not->toContain('stored-secret')
            ->and(json_encode($issue, JSON_THROW_ON_ERROR))->not->toContain('observed-secret');
    });

    it('expects managed database hosts to use the owner node WireGuard service address', function (): void {
        $appNode = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $databaseNode = Node::factory()->database()->create([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.7',
        ]);
        $path = storage_path('framework/testing/database-probe-managed-host');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=10.6.0.7\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n");

        $app = App::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $databaseNode->id,
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'postgres.orbit',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        $issues = app(DatabaseConnectionProbe::class)->probe($appNode);

        expect($issues)->toBe([]);
    });

    it('reports WireGuard self-route diagnostics for same-node managed database hosts', function (): void {
        $node = Node::factory()->gateway()->create([
            'name' => 'gateway-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.7',
        ]);
        $path = storage_path('framework/testing/database-probe-managed-self-route');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=10.6.0.7\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'postgres.orbit',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);
        $shell = new DatabaseConnectionProbeRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "10.6.0.7 dev wg-orbit src 10.6.0.2\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.wireguard_self_route_unavailable');

        expect($issue)->not->toBeNull()
            ->and($issue['kind'])->toBe('unverifiable')
            ->and($issue['detail'])->toMatchArray([
                'target_type' => 'app',
                'app' => 'docs',
                'env_prefix' => 'DB',
                'connection' => 'docs',
                'node' => 'gateway-1',
                'wireguard_address' => '10.6.0.7',
                'host' => '10.6.0.7',
                'reason' => 'self_route_missing',
                'message' => 'Linux node does not route its own WireGuard address locally.',
            ])
            ->and($shell->scripts)->toBe(["ip route get '10.6.0.7'"]);
    });

    it('reports macOS as unsupported for same-node managed database self-route diagnostics without route mutation', function (): void {
        $node = Node::factory()->gateway()->create([
            'name' => 'gateway-1',
            'status' => 'active',
            'platform' => 'macos_15-4',
            'wireguard_address' => '10.6.0.7',
        ]);
        $path = storage_path('framework/testing/database-probe-managed-self-route-macos');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=10.6.0.7\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'postgres.orbit',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);
        $shell = new DatabaseConnectionProbeRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.wireguard_self_route_unavailable');

        expect($issue)->not->toBeNull()
            ->and($issue['detail'])->toMatchArray([
                'platform' => 'macos_15-4',
                'reason' => 'unsupported_platform',
                'message' => NodeWireGuardSelfRouteProbe::UnsupportedMessage,
            ])
            ->and($shell->scripts)->toBe([]);
    });

    it('reads remote env files through remote shell for hosted workspaces', function (): void {
        $node = Node::factory()->appDev()->create(['name' => 'app-1', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $node->id, 'name' => 'docs']);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '/srv/docs/.worktrees/feature',
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'feature-docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'feature_docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forWorkspace($workspace)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        $shell = new DatabaseConnectionProbeRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=feature_docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $issues = app(DatabaseConnectionProbe::class)->probe($node);

        expect($issues)->toBe([])
            ->and($shell->scripts)->not->toBe([])
            ->and($shell->scripts[0])->toContain("test -f '/srv/docs/.worktrees/feature/.env'")
            ->and($shell->scripts[0])->toContain("cat '/srv/docs/.worktrees/feature/.env'");
    });

    it('reports one actionable extra issue per unmapped observed supported prefix', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-extra-app');
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

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $issues = app(DatabaseConnectionProbe::class)->probe($node);
        $keys = collect($issues)->pluck('key')->all();

        expect($keys)->toContain('database_connection.env_extra')
            ->not->toContain('database_connection.target_extra')
            ->and(collect($issues)->where('key', 'database_connection.env_extra')->count())->toBe(2)
            ->and(collect($issues)->count())->toBe(2);
    });

    it('discovers custom complete prefixes as adoptable env extras', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-custom-prefix');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "REPORTING_DB_CONNECTION=pgsql\nREPORTING_DB_HOST=reporting.internal\nREPORTING_DB_PORT=5432\nREPORTING_DB_DATABASE=reporting\nREPORTING_DB_USERNAME=reporting\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.env_extra');

        expect($issue)->not->toBeNull()
            ->and($issue['detail']['env_prefix'] ?? null)->toBe('REPORTING_DB');
    });

    it('reports partial observed prefix groups as unverifiable instead of adoptable extras', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-partial-prefix');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "REPORTING_DB_CONNECTION=pgsql\nREPORTING_DB_HOST=reporting.internal\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $issues = collect(app(DatabaseConnectionProbe::class)->probe($node));

        expect($issues->pluck('key')->all())->toContain('database_connection.unverifiable')
            ->and($issues->pluck('key')->all())->not->toContain('database_connection.env_extra');
    });

    it('ignores non-database Laravel connection env values', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-laravel-prefixes');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "SESSION_DRIVER=database\nBROADCAST_CONNECTION=log\nQUEUE_CONNECTION=database\nCACHE_STORE=database\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        expect(app(DatabaseConnectionProbe::class)->probe($node))->toBe([]);
    });

    it('reports a missing target mapping when observed env matches an existing connection', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-target-missing');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.target_missing');

        expect($issue)->not->toBeNull()
            ->and($issue['detail']['database_connection_id'] ?? null)->toBe($connection->id)
            ->and($issue['detail']['connection'] ?? null)->toBe('docs');
    });

    it('matches missing target mappings for managed database hosts by owner WireGuard address', function (): void {
        $appNode = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $databaseNode = Node::factory()->database()->create([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.7',
        ]);
        $path = storage_path('framework/testing/database-probe-target-missing-managed-host');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=10.6.0.7\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n");

        App::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $databaseNode->id,
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'postgres.orbit',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);

        $issues = collect(app(DatabaseConnectionProbe::class)->probe($appNode));

        expect($issues->pluck('key')->all())->toContain('database_connection.target_missing')
            ->not->toContain('database_connection.env_extra');

        $issue = $issues->firstWhere('key', 'database_connection.target_missing');

        expect($issue['detail']['database_connection_id'] ?? null)->toBe($connection->id)
            ->and($issue['detail']['connection'] ?? null)->toBe('docs');
    });

    it('requires sqlite node ownership when matching missing target mappings', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $otherNode = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/database-probe-sqlite-node');
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

        $keys = collect(app(DatabaseConnectionProbe::class)->probe($node))->pluck('key')->all();

        expect($keys)->not->toContain('database_connection.target_missing')
            ->and($keys)->toContain('database_connection.env_extra');
    });

    it('uses remote shell for hosted nodes even when the same path exists locally', function (): void {
        $node = Node::factory()->appDev()->create(['name' => 'app-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-shadowed-remote');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\nDB_HOST=local-shadow\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'remote-host',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        $shell = new DatabaseConnectionProbeRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=pgsql\nDB_HOST=remote-host\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $issues = app(DatabaseConnectionProbe::class)->probe($node);

        expect($issues)->toBe([])
            ->and($shell->scripts)->not->toBe([]);
    });
});

final class DatabaseConnectionProbeRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected remote shell call', 1);
    }
}
