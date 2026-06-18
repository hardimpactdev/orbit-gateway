<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\AgentIde\CoreAgentIdeWorkspacePathResolver;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves OpenCode workspace paths through the local executor lookup command', function (): void {
    $node = Node::factory()->appDev()->create();
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
    ]);
    $transport = new CoreAgentIdeWorkspacePathResolverTransport(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(JsonEnvelope::success([
            'match' => true,
            'workspace_name' => 'docs-worktree',
            'path' => '/tmp/opencode/docs-worktree',
            'adapter_workspace_id' => 'wrk_docs',
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        stderr: '',
        durationMs: 1,
    ));

    $resolution = (new CoreAgentIdeWorkspacePathResolver(coreAgentIdeWorkspacePathResolverExecutor($transport)))
        ->resolve('opencode', $app, '/tmp/opencode/docs-worktree');

    expect($resolution?->appSlug)->toBe('docs')
        ->and($resolution?->workspaceName)->toBe('docs-worktree')
        ->and($transport->calls)->toHaveCount(1);

    $script = $transport->calls[0]['script'];

    expect($script)->toContain('internal:workspace-adapter:lookup')
        ->and($script)->toContain("--adapter='opencode'")
        ->and($script)->toContain("--lookup='workspace'")
        ->and($script)->toContain("--workspace-path='/tmp/opencode/docs-worktree'")
        ->and($script)->toContain("--app-path='/srv/docs'")
        ->and($script)->toContain('--operation-token=')
        ->and($script)->not->toContain('python3')
        ->and($script)->not->toContain('python -c')
        ->and($script)->not->toContain('sqlite3')
        ->and($script)->not->toContain('php -r');
});

it('resolves Polyscope workspace paths through the local executor lookup command', function (): void {
    $node = Node::factory()->appDev()->create();
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
    ]);
    $transport = new CoreAgentIdeWorkspacePathResolverTransport(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(JsonEnvelope::success([
            'match' => true,
            'workspace_name' => 'feature-docs',
            'path' => '/srv/docs/.worktrees/feature-docs',
            'adapter_workspace_id' => 'poly-worktree-1',
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        stderr: '',
        durationMs: 1,
    ));

    $resolution = (new CoreAgentIdeWorkspacePathResolver(coreAgentIdeWorkspacePathResolverExecutor($transport)))
        ->resolve('polyscope', $app, '/srv/docs/.worktrees/feature-docs');

    expect($resolution?->appSlug)->toBe('docs')
        ->and($resolution?->workspaceName)->toBe('feature-docs')
        ->and($transport->calls)->toHaveCount(1);

    $script = $transport->calls[0]['script'];

    expect($script)->toContain('internal:workspace-adapter:lookup')
        ->and($script)->toContain("--adapter='polyscope'")
        ->and($script)->toContain("--lookup='workspace'")
        ->and($script)->toContain("--workspace-path='/srv/docs/.worktrees/feature-docs'")
        ->and($script)->toContain("--app-path='/srv/docs'")
        ->and($script)->toContain('--operation-token=')
        ->and($script)->not->toContain('python3')
        ->and($script)->not->toContain('python -c')
        ->and($script)->not->toContain('sqlite3')
        ->and($script)->not->toContain('php -r');
});

function coreAgentIdeWorkspacePathResolverExecutor(CoreAgentIdeWorkspacePathResolverTransport $transport): RemoteLocalExecutor
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

final class CoreAgentIdeWorkspacePathResolverTransport implements RemoteExecutor
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
