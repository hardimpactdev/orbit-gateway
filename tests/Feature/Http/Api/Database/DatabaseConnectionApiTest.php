<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orbit\Core\Security\OperationTokenSigner;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const DATABASE_API_CALLER_WG_IP = '10.9.0.97';

function createDatabaseApiCallerNode(array $overrides = []): Node
{
    config([
        'orbit.operation_token_ttl_seconds' => 120,
    ]);

    return Node::factory()->create(array_merge([
        'name' => 'database-api-caller',
        'host' => DATABASE_API_CALLER_WG_IP,
        'wireguard_address' => DATABASE_API_CALLER_WG_IP], $overrides));
}

function assignDatabaseApiGatewayRole(Node $node): void
{
    assignDatabaseApiRole($node, 'gateway');
}

function assignDatabaseApiRole(Node $node, string $role, string $status = 'active'): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status]);
}

/**
 * @param  list<string>  $permissions
 */
function grantDatabaseApiAccess(Node $consumer, Node $serving, array $permissions): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $serving->id,
        'permissions' => $permissions]);
}

describe('database connection api', function (): void {
    it('allows active non-gateway callers with database permissions to use registry endpoints', function (): void {
        $caller = createDatabaseApiCallerNode();
        $node = createTestAppHostNode(['name' => 'db-node']);
        grantDatabaseApiAccess($caller, $node, ['database:read', 'database:write']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $connection = DatabaseConnection::factory()->create(['slug' => 'primary-db', 'node_id' => $node->id]);
        DatabaseConnectionTarget::factory()->for($connection, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);

        $listResponse = $this->call('GET', '/api/database-connections', [], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);
        $attachResponse = $this->call('POST', '/api/database-connections/primary-db/targets', [
            'app' => 'docs',
            'env_prefix' => 'ANALYTICS_DB'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $listResponse->assertOk()
            ->assertJsonPath('success.data.connections.0.slug', 'primary-db');
        $attachResponse->assertOk();

        expect($attachResponse->json('success.data.connection.targets'))
            ->toContain([
                'type' => 'app',
                'name' => 'docs',
                'env_prefix' => 'ANALYTICS_DB']);
    });

    it('allows granted app-role callers without treating the role as a blocker', function (): void {
        $caller = createDatabaseApiCallerNode([
            'name' => 'database-api-app-caller',
            'host' => '10.9.0.98',
            'wireguard_address' => '10.9.0.98']);
        assignDatabaseApiRole($caller, 'app-dev');
        $node = createTestAppHostNode(['name' => 'db-node']);
        grantDatabaseApiAccess($caller, $node, ['database:read']);
        DatabaseConnection::factory()->create(['slug' => 'primary-db', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/database-connections', [], [], [], ['REMOTE_ADDR' => '10.9.0.98']);

        $response->assertOk()
            ->assertJsonPath('success.data.connections.0.slug', 'primary-db');
    });

    it('executes database queries through the typed api without leaking sqlite credentials', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'db-node']);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs-db',
            'node_id' => $node->id,
            'driver' => 'sqlite',
            'host' => null,
            'port' => null,
            'database' => null,
            'path' => '/srv/docs/database/database.sqlite',
            'username' => null,
            'credentials' => ['password' => 'never-print-me']]);
        $shell = new DatabaseApiQueryRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'columns' => ['id'],
                        'rows' => [['id' => 1]]],
                    'meta' => ['mode' => 'read', 'returned_rows' => 1]]], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        ));
        bindDatabaseApiLocalExecutor($shell);

        $response = $this->call('POST', '/api/database-connections/query', [
            'target' => $connection->slug,
            'sql' => 'select id from users'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.rows.0.id', 1)
            ->assertJsonPath('success.meta.connection', 'docs-db');

        expect($response->getContent())->not->toContain('never-print-me')
            ->and($shell->script)->not->toContain('never-print-me')
            ->and($shell->script)->toContain('/usr/local/bin/orbit internal:database-query-local')
            ->and($shell->options)->toHaveKey('input');
    });

    it('executes schema api requests through the typed api', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'db-node']);
        DatabaseConnection::factory()->create([
            'slug' => 'docs-db',
            'node_id' => $node->id,
            'driver' => 'sqlite',
            'host' => null,
            'port' => null,
            'database' => null,
            'path' => '/srv/docs/database/database.sqlite',
            'username' => null]);
        bindDatabaseApiLocalExecutor(new DatabaseApiQueryRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'columns' => ['name'],
                        'rows' => [['name' => 'users']]],
                    'meta' => ['mode' => 'read', 'returned_rows' => 1]]], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        )));

        $response = $this->call('GET', '/api/database-connections/tables', [
            'target' => 'docs-db'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.rows.0.name', 'users')
            ->assertJsonPath('success.meta.connection', 'docs-db');
    });

    it('separates read-only database query permission from write query permission', function (): void {
        $caller = createDatabaseApiCallerNode();
        $node = createTestAppHostNode(['name' => 'db-node']);
        grantDatabaseApiAccess($caller, $node, ['database:query']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs-db',
            'node_id' => $node->id,
            'driver' => 'sqlite',
            'host' => null,
            'port' => null,
            'database' => null,
            'path' => '/srv/docs/database/database.sqlite',
            'username' => null]);
        DatabaseConnectionTarget::factory()->for($connection, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);
        bindDatabaseApiLocalExecutor(new DatabaseApiQueryRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'columns' => ['id'],
                        'rows' => [['id' => 1]]],
                    'meta' => ['mode' => 'read', 'returned_rows' => 1]]], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        )));

        $readResponse = $this->call('POST', '/api/database-connections/query', [
            'target' => 'docs',
            'sql' => 'select id from users'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);
        $writeResponse = $this->call('POST', '/api/database-connections/query', [
            'target' => 'docs',
            'sql' => 'delete from users where id = 1',
            'write' => true], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $readResponse->assertOk()
            ->assertJsonPath('success.data.rows.0.id', 1);
        $writeResponse->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'database:query:write');
    });

    it('rejects active app and database callers from registry endpoints without database grants', function (): void {
        $appCaller = createDatabaseApiCallerNode([
            'name' => 'database-api-app-caller',
            'host' => '10.9.0.98',
            'wireguard_address' => '10.9.0.98']);
        assignDatabaseApiRole($appCaller, 'app-dev');

        $databaseCaller = createDatabaseApiCallerNode([
            'name' => 'database-api-database-caller',
            'host' => '10.9.0.99',
            'wireguard_address' => '10.9.0.99']);
        assignDatabaseApiRole($databaseCaller, 'database');

        $appResponse = $this->call('GET', '/api/database-connections', [], [], [], ['REMOTE_ADDR' => '10.9.0.98']);
        $databaseResponse = $this->call('GET', '/api/database-connections', [], [], [], ['REMOTE_ADDR' => '10.9.0.99']);

        $appResponse->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'database:read');
        $databaseResponse->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'database:read');
    });

    it('rejects unassigned app and database named callers without active assignments', function (): void {
        createDatabaseApiCallerNode([
            'name' => 'database-api-unassigned-app-caller',
            'host' => '10.9.0.100',
            'wireguard_address' => '10.9.0.100']);
        createDatabaseApiCallerNode([
            'name' => 'database-api-unassigned-database-caller',
            'host' => '10.9.0.101',
            'wireguard_address' => '10.9.0.101']);

        $appResponse = $this->call('GET', '/api/database-connections', [], [], [], ['REMOTE_ADDR' => '10.9.0.100']);
        $databaseResponse = $this->call('GET', '/api/database-connections', [], [], [], ['REMOTE_ADDR' => '10.9.0.101']);

        $appResponse->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'database:read');
        $databaseResponse->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'database:read');
    });

    it('rejects inactive control callers from registry endpoints', function (): void {
        createDatabaseApiCallerNode(['status' => 'inactive']);

        $response = $this->call('GET', '/api/database-connections', [], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });

    it('lists and shows canonical database entities without passwords', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'db-node']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'primary-db',
            'node_id' => $node->id,
            'credentials' => ['password' => 'secret']]);
        DatabaseConnectionTarget::factory()->for($connection, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);

        $listResponse = $this->call('GET', '/api/database-connections', [], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);
        $showResponse = $this->call('GET', '/api/database-connections/primary-db', [], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $listResponse->assertOk()
            ->assertJsonPath('success.meta.count', 1)
            ->assertJsonPath('success.data.connections.0.slug', 'primary-db');
        $showResponse->assertOk()
            ->assertJsonPath('success.data.connection.slug', 'primary-db')
            ->assertJsonPath('success.data.connection.targets.0', [
                'type' => 'app',
                'name' => 'docs',
                'env_prefix' => 'DB']);

        expect($listResponse->getContent())->not->toContain('secret')
            ->and($showResponse->getContent())->not->toContain('secret');
    });

    it('creates, updates, attaches, detaches, and removes connections with activity logs', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'db-node']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);

        $createResponse = $this->call('POST', '/api/database-connections', [
            'slug' => 'primary-db',
            'driver' => 'pgsql',
            'host' => 'postgres.internal',
            'port' => 5432,
            'database' => 'orbit',
            'username' => 'orbit',
            'password' => 'secret',
            'node' => 'db-node'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $createResponse->assertOk()
            ->assertJsonPath('success.data.connection.slug', 'primary-db');

        $connection = DatabaseConnection::query()->where('slug', 'primary-db')->firstOrFail();

        expect($createResponse->getContent())->not->toContain('secret')
            ->and($connection->credentials)->toBe(['password' => 'secret']);

        $updateResponse = $this->call('PATCH', '/api/database-connections/primary-db', [
            'slug' => 'renamed-db',
            'clear_password' => true], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $updateResponse->assertOk()
            ->assertJsonPath('success.data.connection.slug', 'renamed-db');

        expect(DatabaseConnection::query()->where('slug', 'renamed-db')->firstOrFail()->credentials)->toBe([]);

        $attachResponse = $this->call('POST', '/api/database-connections/renamed-db/targets', [
            'workspace' => 'feature-docs',
            'env_prefix' => 'ANALYTICS_DB'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $attachResponse->assertOk()
            ->assertJsonPath('success.data.connection.targets.0', [
                'type' => 'workspace',
                'name' => 'feature-docs',
                'env_prefix' => 'ANALYTICS_DB']);

        $detachResponse = $this->call('DELETE', '/api/database-connections/renamed-db/targets', [
            'workspace' => 'feature-docs',
            'env_prefix' => 'ANALYTICS_DB'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $detachResponse->assertOk()
            ->assertJsonPath('success.data.result', [
                'action' => 'detached',
                'connection' => 'renamed-db',
                'target_type' => 'workspace',
                'target' => 'feature-docs',
                'env_prefix' => 'ANALYTICS_DB']);

        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => DatabaseConnection::query()->where('slug', 'renamed-db')->firstOrFail()->id,
            'env_prefix' => 'DB']);

        $removeResponse = $this->call('DELETE', '/api/database-connections/renamed-db', [
            'force' => true], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $removeResponse->assertOk()
            ->assertJsonPath('success.data.result', [
                'action' => 'removed',
                'connection' => 'renamed-db']);

        expect(DatabaseConnection::query()->where('slug', 'renamed-db')->exists())->toBeFalse()
            ->and(DatabaseConnectionTarget::query()->count())->toBe(0);

        $properties = Activity::query()->pluck('properties')->all();
        $loggedJson = json_encode($properties, JSON_THROW_ON_ERROR);

        expect($loggedJson)->not->toContain('secret')
            ->and($loggedJson)->toContain('renamed-db')
            ->and($loggedJson)->toContain('ANALYTICS_DB');
    });

    it('returns documented validation and not-found error envelopes', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'db-node']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $connection = DatabaseConnection::factory()->create(['slug' => 'primary-db']);
        DatabaseConnectionTarget::factory()->for($connection, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);

        $removeWithoutForce = $this->call('DELETE', '/api/database-connections/primary-db', [], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);
        $missingShow = $this->call('GET', '/api/database-connections/missing-db', [], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);
        $invalidAttach = $this->call('POST', '/api/database-connections/primary-db/targets', [
            'app' => 'docs',
            'workspace' => 'feature-docs'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $removeWithoutForce->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force')
            ->assertJsonPath('error.meta.reason', 'destructive_consent_required');
        $missingShow->assertNotFound()
            ->assertJsonPath('error.code', 'database_connection.not_found');
        $invalidAttach->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
    });

    it('does not leak passwords in api validation errors', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);

        $response = $this->call('POST', '/api/database-connections', [
            'slug' => 'broken-db',
            'driver' => 'pgsql',
            'host' => 'postgres.internal',
            'password' => 'super-secret'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        expect($response->getContent())->not->toContain('super-secret');
    });

    it('fails create and update when api node selectors are invalid', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        DatabaseConnection::factory()->create(['slug' => 'primary-db']);

        $createResponse = $this->call('POST', '/api/database-connections', [
            'slug' => 'broken-db',
            'driver' => 'sqlite',
            'node' => 'missing-node',
            'path' => '/srv/orbit/database.sqlite'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $updateResponse = $this->call('PATCH', '/api/database-connections/primary-db', [
            'node' => 'missing-node'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $createResponse->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node');
        $updateResponse->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node');
    });

    it('does not rewrite env files during attach and detach', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'db-node']);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/srv/apps/docs']);
        $connection = DatabaseConnection::factory()->create(['slug' => 'primary-db']);

        $before = DB::table('database_connection_targets')->count();

        $attachResponse = $this->call('POST', '/api/database-connections/primary-db/targets', [
            'app' => 'docs',
            'env_prefix' => 'DB'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $attachResponse->assertOk();

        $detachResponse = $this->call('DELETE', '/api/database-connections/primary-db/targets', [
            'app' => 'docs',
            'env_prefix' => 'DB'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $detachResponse->assertOk();

        expect($before)->toBe(0)
            ->and(DB::table('database_connection_targets')->count())->toBe(0)
            ->and(file_exists('/srv/apps/docs/.env'))->toBeFalse();
    });

    it('rejects explicit query connections that are not attached to the target', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'db-node']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $attached = DatabaseConnection::factory()->create(['slug' => 'docs-db', 'node_id' => $node->id]);
        $other = DatabaseConnection::factory()->create(['slug' => 'other-db', 'node_id' => $node->id]);
        DatabaseConnectionTarget::factory()->for($attached, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);

        $response = $this->call('POST', '/api/database-connections/query', [
            'target' => 'docs',
            'connection' => $other->slug,
            'sql' => 'select 1'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'database_connection.target_not_found')
            ->assertJsonPath('error.meta.slug', 'other-db');
    });

    it('returns ambiguity errors for query targets with multiple non-default mappings', function (): void {
        $caller = createDatabaseApiCallerNode();
        assignDatabaseApiGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'db-node']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $primary = DatabaseConnection::factory()->create(['slug' => 'primary-db', 'node_id' => $node->id]);
        $analytics = DatabaseConnection::factory()->create(['slug' => 'analytics-db', 'node_id' => $node->id]);
        DatabaseConnectionTarget::factory()->for($primary, 'connection')->forApp($app)->create(['env_prefix' => 'PRIMARY_DB']);
        DatabaseConnectionTarget::factory()->for($analytics, 'connection')->forApp($app)->create(['env_prefix' => 'ANALYTICS_DB']);

        $response = $this->call('POST', '/api/database-connections/query', [
            'target' => 'docs',
            'sql' => 'select 1'], [], [], ['REMOTE_ADDR' => DATABASE_API_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'database_connection.ambiguous_target')
            ->assertJsonPath('error.meta.connections', ['analytics-db', 'primary-db']);
    });
});

function bindDatabaseApiLocalExecutor(DatabaseApiQueryRemoteShell $transport): void
{
    app()->instance(RemoteLocalExecutor::class, new RemoteLocalExecutor(
        transport: $transport,
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: 'gateway-secret',
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        operationTokenSecret: 'gateway-secret',
    ));
}

final class DatabaseApiQueryRemoteShell implements RemoteExecutor
{
    public string $script = '';

    /** @var array<string, mixed> */
    public array $options = [];

    public function __construct(private readonly RemoteShellResult $result) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->script = $script;
        $this->options = $options;

        return $this->result;
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('Process start is not used in this test.');
    }
}
