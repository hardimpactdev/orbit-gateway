<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\DatabaseConnections\DatabaseConnectionExecutor;
use App\Services\DatabaseConnections\DatabaseQueryRunner;
use App\Services\DatabaseConnections\DatabaseSchemaInspector;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe(DatabaseConnectionExecutor::class, function (): void {
    it('dispatches sqlite queries through the hidden internal local executor command without leaking connection secrets', function (): void {
        $node = Node::factory()->appDev()->create(['name' => 'app-node']);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'docs-db',
            'driver' => 'sqlite',
            'host' => null,
            'port' => null,
            'database' => null,
            'path' => '/srv/docs/database/database.sqlite',
            'username' => null,
            'credentials' => ['password' => 'never-print-me'],
        ]);
        $transport = new DatabaseConnectionExecutorRecordingTransport(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'columns' => ['id'],
                        'rows' => [['id' => 1]],
                    ],
                    'meta' => ['mode' => 'read'],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        ));
        $executor = new DatabaseConnectionExecutor(
            runner: app(DatabaseQueryRunner::class),
            inspector: app(DatabaseSchemaInspector::class),
            localExecutor: databaseConnectionExecutorRemoteLocalExecutor($transport),
        );

        $result = $executor->query($connection, 'select id from users', ['limit' => 5]);

        expect($result['data']['rows'])->toBe([['id' => 1]])
            ->and($transport->calls)->toHaveCount(1)
            ->and($transport->calls[0]['node']->is($node))->toBeTrue()
            ->and($transport->calls[0]['script'])->toContain('/usr/local/bin/orbit internal:database-query-local')
            ->and($transport->calls[0]['script'])->not->toContain('orbit database:query-local')
            ->and($transport->calls[0]['script'])->not->toContain('never-print-me')
            ->and($transport->calls[0]['options'])->toHaveKeys(['input', 'throw', 'strict'])
            ->and($transport->calls[0]['options']['throw'])->toBeFalse()
            ->and($transport->calls[0]['options']['strict'])->toBeTrue();

        $input = json_decode($transport->calls[0]['options']['input'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($input['connection']['path'])->toBe('/srv/docs/database/database.sqlite')
            ->and($input['connection'])->not->toHaveKey('credentials')
            ->and($input['connection'])->not->toHaveKey('password')
            ->and($input['sql'])->toBe('select id from users')
            ->and($input['limit'])->toBe(5)
            ->and($input['write'])->toBeFalse();
    });
});

function databaseConnectionExecutorRemoteLocalExecutor(DatabaseConnectionExecutorRecordingTransport $transport): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
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
    );
}

final class DatabaseConnectionExecutorRecordingTransport implements RemoteExecutor
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(private readonly RemoteShellResult $result) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node,
            'script' => $script,
            'options' => $options,
        ];

        return $this->result;
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('Process start is not used in this test.');
    }
}
