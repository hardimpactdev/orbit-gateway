<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Workspaces\PolyscopeWorkspaceBranchAligner;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('aligns a Polyscope workspace branch through the app node', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'beast']);
    $hostShell = new PolyscopeBranchAlignerRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '{"branch":"cta"}', stderr: '', durationMs: 1),
    );
    $localTransport = new PolyscopeBranchAlignerLocalTransport(
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'adapter' => 'polyscope',
                'update' => 'workspace-branch',
                'workspace_id' => 'wt-1',
                'branch' => 'cta',
                'updated' => true,
            ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            stderr: '',
            durationMs: 1,
        ),
    );

    (new PolyscopeWorkspaceBranchAligner(
        remoteShell: $hostShell,
        localExecutor: polyscopeBranchAlignerLocalExecutor($localTransport),
    ))->align(
        node: $node,
        workspaceId: 'wt-1',
        path: '/home/nckrtl/.polyscope/clones/6dad0913/young-bat',
        name: 'cta',
    );

    expect($hostShell->runs)->toHaveCount(1)
        ->and($hostShell->runs[0]['node'])->toBe('beast')
        ->and($hostShell->runs[0]['options']['metadata'])->toMatchArray([
            'ORBIT_POLYSCOPE_WORKSPACE_PATH' => '/home/nckrtl/.polyscope/clones/6dad0913/young-bat',
            'ORBIT_WORKSPACE_NAME' => 'cta',
        ])
        ->and($hostShell->runs[0]['script'])->toContain('git', 'branch', '-m')
        ->and($hostShell->runs[0]['script'])->not->toContain('python3')
        ->and($hostShell->runs[0]['script'])->not->toContain('python -c')
        ->and($hostShell->runs[0]['script'])->not->toContain('sqlite3')
        ->and($hostShell->runs[0]['script'])->not->toContain('update worktrees');

    expect($localTransport->calls)->toHaveCount(1);

    $localScript = $localTransport->calls[0]['script'];

    expect($localScript)->toContain('internal:workspace-adapter:update')
        ->and($localScript)->toContain("--adapter='polyscope'")
        ->and($localScript)->toContain("--update='workspace-branch'")
        ->and($localScript)->toContain("--workspace-id='wt-1'")
        ->and($localScript)->toContain("--branch='cta'")
        ->and($localScript)->toContain('--operation-token=')
        ->and($localScript)->not->toContain('python3')
        ->and($localScript)->not->toContain('python -c')
        ->and($localScript)->not->toContain('sqlite3');
});

it('does not leak host branch rename output when a Polyscope branch cannot be aligned', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'beast']);
    $secret = 'remote-host-secret';
    $hostShell = new PolyscopeBranchAlignerRecordingShell(
        new RemoteShellResult(exitCode: 1, stdout: "stdout {$secret}", stderr: "stderr {$secret}", durationMs: 1),
    );
    $localTransport = new PolyscopeBranchAlignerLocalTransport(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    try {
        (new PolyscopeWorkspaceBranchAligner(
            remoteShell: $hostShell,
            localExecutor: polyscopeBranchAlignerLocalExecutor($localTransport),
        ))->align(
            node: $node,
            workspaceId: 'wt-1',
            path: '/home/nckrtl/.polyscope/clones/6dad0913/young-bat',
            name: 'cta',
        );

        $this->fail('Expected Polyscope branch alignment to fail.');
    } catch (WorkspaceCreateFailed $exception) {
        expect($exception->getMessage())->toBe('Polyscope workspace was created but could not be renamed.')
            ->and(polyscopeBranchAlignerExceptionBlob($exception))->not->toContain($secret)
            ->and($exception->meta)->toMatchArray([
                'adapter' => 'polyscope',
                'reason' => 'branch_rename_failed',
            ]);
    }

    expect($localTransport->calls)->toBeEmpty();
});

it('does not leak local executor output when Polyscope adapter metadata cannot be updated', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'beast']);
    $secret = 'remote-update-secret';
    $hostShell = new PolyscopeBranchAlignerRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '{"branch":"cta"}', stderr: '', durationMs: 1),
    );
    $localTransport = new PolyscopeBranchAlignerLocalTransport(
        new RemoteShellResult(
            exitCode: 1,
            stdout: json_encode(JsonEnvelope::failure(
                'update_failed',
                "secret {$secret}",
                ['secret' => $secret],
            ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            stderr: "stderr {$secret}",
            durationMs: 1,
        ),
    );

    try {
        (new PolyscopeWorkspaceBranchAligner(
            remoteShell: $hostShell,
            localExecutor: polyscopeBranchAlignerLocalExecutor($localTransport),
        ))->align(
            node: $node,
            workspaceId: 'wt-1',
            path: '/home/nckrtl/.polyscope/clones/6dad0913/young-bat',
            name: 'cta',
        );

        $this->fail('Expected Polyscope branch alignment to fail.');
    } catch (WorkspaceCreateFailed $exception) {
        expect($exception->getMessage())->toBe('Polyscope workspace was created but could not be renamed.')
            ->and(polyscopeBranchAlignerExceptionBlob($exception))->not->toContain($secret)
            ->and($exception->meta)->toMatchArray([
                'adapter' => 'polyscope',
                'reason' => 'workspace_adapter_update_failed',
                'adapter_error_code' => 'update_failed',
            ]);
    }
});

final class PolyscopeBranchAlignerRecordingShell implements RemoteShell
{
    /** @var list<array{node: string, script: string, options: array<string, mixed>}> */
    public array $runs = [];

    public function __construct(
        private readonly RemoteShellResult $result,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = [
            'node' => $node->name,
            'script' => $script,
            'options' => $options,
        ];

        return $this->result;
    }
}

final class PolyscopeBranchAlignerLocalTransport implements RemoteExecutor
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

function polyscopeBranchAlignerLocalExecutor(PolyscopeBranchAlignerLocalTransport $transport): RemoteLocalExecutor
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

function polyscopeBranchAlignerExceptionBlob(WorkspaceCreateFailed $exception): string
{
    return json_encode([
        'message' => $exception->getMessage(),
        'errorCode' => $exception->errorCode,
        'code' => $exception->getCode(),
        'meta' => $exception->meta,
        'trace' => $exception->getTraceAsString(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
