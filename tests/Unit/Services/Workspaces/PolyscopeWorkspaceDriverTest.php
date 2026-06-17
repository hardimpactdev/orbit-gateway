<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Workspaces\PolyscopeWorkspaceBranchAligner;
use App\Services\Workspaces\PolyscopeWorkspaceDriver;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('reads Polyscope config through the local executor lookup command with stdout suppressed in activity logs', function (): void {
    $node = polyscopeWorkspaceDriverAppDevNode();
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'agent_ide_config' => null,
    ]);
    $transport = new PolyscopeWorkspaceDriverTransport(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(JsonEnvelope::success([
            'api_token' => 'poly-token-secret',
            'server_id' => null,
            'repository_id' => 'repo-docs',
            'base_url' => 'https://polyscope.test',
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        stderr: '',
        durationMs: 2,
    ));

    $driver = new PolyscopeWorkspaceDriver(
        branchAligner: polyscopeWorkspaceDriverUnusedBranchAligner(),
        localExecutor: polyscopeWorkspaceDriverExecutor($transport),
    );

    try {
        $driver->create($app, $node, 'feature-docs', 'main');

        $this->fail('Expected Polyscope workspace creation to fail before creating a remote workspace.');
    } catch (WorkspaceCreateFailed $exception) {
        expect($exception->errorCode)->toBe('workspace.agent_ide_not_configured')
            ->and($exception->meta['missing'])->toBe(['server_id']);
    }

    expect($transport->calls)->toHaveCount(1);

    $script = $transport->calls[0]['script'];

    expect($script)->toContain('internal:workspace-adapter:lookup')
        ->and($script)->toContain("--adapter='polyscope'")
        ->and($script)->toContain("--lookup='config'")
        ->and($script)->toContain("--app-path='/srv/docs'")
        ->and($script)->toContain('--operation-token=')
        ->and($script)->not->toContain('python3')
        ->and($script)->not->toContain('python -c')
        ->and($script)->not->toContain('sqlite3')
        ->and($script)->not->toContain('php -r');

    $completed = polyscopeWorkspaceDriverLocalExecutorActivityRows()[1];
    $properties = json_decode((string) $completed->properties, true, flags: JSON_THROW_ON_ERROR);

    expect($properties['stdout_summary'])->toBe('<suppressed>')
        ->and(json_encode($properties, JSON_THROW_ON_ERROR))->not->toContain('poly-token-secret');
});

it('does not leak Polyscope api tokens from config lookup output into workspace exceptions', function (Closure $resultFactory, array $expectedMeta): void {
    $secret = 'poly-token-secret-round-2';
    $node = polyscopeWorkspaceDriverAppDevNode();
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'agent_ide_config' => null,
    ]);
    $transport = new PolyscopeWorkspaceDriverTransport($resultFactory());
    $driver = new PolyscopeWorkspaceDriver(
        branchAligner: polyscopeWorkspaceDriverUnusedBranchAligner(),
        localExecutor: polyscopeWorkspaceDriverExecutor($transport),
    );

    try {
        $driver->create($app, $node, 'feature-docs', 'main');

        $this->fail('Expected Polyscope workspace creation to fail.');
    } catch (WorkspaceCreateFailed $exception) {
        expect($exception->getMessage())->not->toContain($secret)
            ->and(polyscopeWorkspaceDriverExceptionBlob($exception))->not->toContain($secret)
            ->and($exception->meta)->toMatchArray($expectedMeta);
    }
})->with([
    'malformed output' => [
        fn (): RemoteShellResult => new RemoteShellResult(
            exitCode: 1,
            stdout: 'not-json api_token=poly-token-secret-round-2',
            stderr: 'stderr api_token=poly-token-secret-round-2',
            durationMs: 2,
        ),
        ['reason' => 'Polyscope config lookup returned unparseable output.'],
    ],
    'non-success envelope' => [
        fn (): RemoteShellResult => new RemoteShellResult(
            exitCode: 1,
            stdout: json_encode([
                'error' => [
                    'code' => 'adapter_database_missing',
                    'message' => 'Workspace adapter database does not exist.',
                ],
                'meta' => [
                    'api_token' => 'poly-token-secret-round-2',
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            stderr: 'stderr api_token=poly-token-secret-round-2',
            durationMs: 2,
        ),
        [
            'adapter_error_code' => 'adapter_database_missing',
            'reason' => 'Polyscope configuration lookup failed.',
        ],
    ],
]);

it('treats Polyscope config lookup error messages as untrusted remote output', function (): void {
    $secret = 'secret-token-probe-XYZ';
    $node = polyscopeWorkspaceDriverAppDevNode();
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'agent_ide_config' => null,
    ]);
    $transport = new PolyscopeWorkspaceDriverTransport(new RemoteShellResult(
        exitCode: 1,
        stdout: json_encode([
            'error' => [
                'code' => 'some_code',
                'message' => "leak: {$secret}",
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        stderr: '',
        durationMs: 2,
    ));
    $driver = new PolyscopeWorkspaceDriver(
        branchAligner: polyscopeWorkspaceDriverUnusedBranchAligner(),
        localExecutor: polyscopeWorkspaceDriverExecutor($transport),
    );

    try {
        $driver->create($app, $node, 'feature-docs', 'main');

        $this->fail('Expected Polyscope workspace creation to fail.');
    } catch (WorkspaceCreateFailed $exception) {
        $exceptionBlob = polyscopeWorkspaceDriverExceptionBlob($exception);

        expect($exception->getMessage())->not->toContain($secret)
            ->and($exception->errorCode)->not->toContain($secret)
            ->and((string) $exception->getCode())->not->toContain($secret)
            ->and($exceptionBlob)->not->toContain($secret)
            ->and($exception->getTraceAsString())->not->toContain($secret)
            ->and($exception->meta)->not->toHaveKey('adapter_error_code')
            ->and($exception->meta['reason'])->toBe('Polyscope configuration lookup failed.');
    }
});

function polyscopeWorkspaceDriverExecutor(PolyscopeWorkspaceDriverTransport $transport): RemoteLocalExecutor
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

function polyscopeWorkspaceDriverUnusedBranchAligner(): PolyscopeWorkspaceBranchAligner
{
    return new PolyscopeWorkspaceBranchAligner(
        remoteShell: new PolyscopeWorkspaceDriverUnusedShell,
        localExecutor: polyscopeWorkspaceDriverExecutor(new PolyscopeWorkspaceDriverTransport(
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        )),
    );
}

function polyscopeWorkspaceDriverAppDevNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'app-dev',
        'agent_ide_config' => null,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    return $node;
}

function polyscopeWorkspaceDriverExceptionBlob(WorkspaceCreateFailed $exception): string
{
    return json_encode([
        'message' => $exception->getMessage(),
        'error_code' => $exception->errorCode,
        'meta' => $exception->meta,
    ], JSON_THROW_ON_ERROR);
}

/**
 * @return list<object>
 */
function polyscopeWorkspaceDriverLocalExecutorActivityRows(): array
{
    return DB::table('activity_log')
        ->where('log_name', 'local_executor')
        ->orderBy('id')
        ->get()
        ->all();
}

final class PolyscopeWorkspaceDriverTransport implements RemoteExecutor
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(
        private readonly RemoteShellResult $result,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node,
            'script' => $script,
            'options' => $options,
        ];

        return $this->result;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('The recording transport does not start processes.');
    }
}

final class PolyscopeWorkspaceDriverUnusedShell implements RemoteShell
{
    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException('The unused remote shell should not run.');
    }
}
