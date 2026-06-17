<?php

declare(strict_types=1);

use App\Contracts\OpenCodeClientFactory;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;
use App\Services\Workspaces\OpenCodeWorkspaceDriver;
use HardImpact\OpenCode\OpenCode;
use HardImpact\OpenCode\Requests\Projects\GetCurrentProject;
use HardImpact\OpenCode\Requests\Sessions\CreateSession;
use HardImpact\OpenCode\Requests\Worktrees\CreateWorktree;
use HardImpact\OpenCode\Requests\Worktrees\RemoveWorktree;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

uses(TestCase::class);

it('creates an OpenCode workspace and aligns it to the requested branch', function (): void {
    $mock = new MockClient([
        MockResponse::make(openCodeProjectPayload(sandboxes: [])),
        MockResponse::make([]),
        MockResponse::make(openCodeWorkspacePayload()),
        MockResponse::make(openCodeSessionPayload()),
    ]);

    $driver = openCodeWorkspaceDriver($mock, $shell = new OpenCodeWorkspaceDriverTestShell);

    $result = $driver->create(openCodeWorkspaceApp(), openCodeWorkspaceNode(), 'feature-a', 'main');

    expect($result->name)->toBe('feature-a')
        ->and($result->path)->toBe('/srv/demo/.worktrees/feature-a')
        ->and($result->agentIde)->toBe('opencode')
        ->and($result->agentIdeWorkspaceId)->toBe('sess_feature_a')
        ->and($shell->scripts)->toHaveCount(1)
        ->and($shell->options[0]['metadata'])->toMatchArray([
            'ORBIT_WORKSPACE_PATH' => '/srv/demo/.worktrees/feature-a',
            'ORBIT_WORKSPACE_NAME' => 'feature-a',
            'ORBIT_WORKSPACE_BASE' => 'main',
        ])
        ->and($shell->scripts[0])->toContain('git -C "$workspace_path" branch -m "$workspace_name"')
        ->and($shell->scripts[0])->toContain('git -C "$workspace_path" reset --hard "$base_ref"');

    $mock->assertSentCount(1, GetCurrentProject::class);
    $mock->assertSentCount(1, CreateWorktree::class);
    $mock->assertSentCount(1, CreateSession::class);
});

it('cleans up the OpenCode workspace when branch alignment fails', function (): void {
    $mock = new MockClient([
        MockResponse::make(openCodeProjectPayload(sandboxes: [])),
        MockResponse::make([]),
        MockResponse::make(openCodeWorkspacePayload()),
        MockResponse::make(openCodeWorkspacePayload()),
    ]);

    $driver = openCodeWorkspaceDriver($mock, $shell = new OpenCodeWorkspaceDriverTestShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'reset failed', durationMs: 1),
    ]));

    expect(fn () => $driver->create(openCodeWorkspaceApp(), openCodeWorkspaceNode(), 'feature-a', 'main'))
        ->toThrow(WorkspaceCreateFailed::class, 'OpenCode could not create the workspace.');

    expect($shell->scripts)->toHaveCount(1);

    $mock->assertSentCount(1, GetCurrentProject::class);
    $mock->assertSentCount(1, CreateWorktree::class);
    $mock->assertSentCount(1, RemoveWorktree::class);
    $mock->assertSentCount(0, CreateSession::class);
});

it('recovers when OpenCode creates a workspace but returns a timeout response', function (): void {
    $mock = new MockClient([
        MockResponse::make(openCodeProjectPayload(sandboxes: [])),
        MockResponse::make([]),
        MockResponse::make(['name' => 'UnknownError'], 500),
        MockResponse::make(['/srv/demo/.worktrees/feature-a']),
        MockResponse::make(openCodeSessionPayload()),
    ]);

    $driver = openCodeWorkspaceDriver($mock, $shell = new OpenCodeWorkspaceDriverTestShell);

    $result = $driver->create(openCodeWorkspaceApp(), openCodeWorkspaceNode(), 'feature-a', 'main');

    expect($result->name)->toBe('feature-a')
        ->and($result->path)->toBe('/srv/demo/.worktrees/feature-a')
        ->and($shell->scripts)->toHaveCount(1);

    $mock->assertSentCount(1, GetCurrentProject::class);
    $mock->assertSentCount(1, CreateWorktree::class);
    $mock->assertSentCount(1, CreateSession::class);
});

function openCodeWorkspaceDriver(MockClient $mock, OpenCodeWorkspaceDriverTestShell $shell): OpenCodeWorkspaceDriver
{
    $client = new OpenCode('http://opencode.test');
    $client->withMockClient($mock);

    return new OpenCodeWorkspaceDriver(
        clientFactory: new OpenCodeWorkspaceDriverTestClientFactory($client),
        remoteShell: $shell,
    );
}

function openCodeWorkspaceApp(): App
{
    return (new App)->forceFill([
        'name' => 'demo',
        'path' => '/srv/demo',
    ]);
}

function openCodeWorkspaceNode(): Node
{
    return (new Node)->forceFill([
        'name' => 'app-1',
        'host' => '10.6.0.7',
    ]);
}

/**
 * @param  list<string>  $sandboxes
 * @return array<string, mixed>
 */
function openCodeProjectPayload(array $sandboxes): array
{
    return [
        'id' => 'proj_demo',
        'worktree' => '/srv/demo',
        'vcs' => 'git',
        'time' => ['created' => 1, 'updated' => 1],
        'sandboxes' => $sandboxes,
    ];
}

/**
 * @return array<string, mixed>
 */
function openCodeWorkspacePayload(): array
{
    return [
        'name' => 'feature-a',
        'branch' => 'opencode/feature-a',
        'directory' => '/srv/demo/.worktrees/feature-a',
    ];
}

/**
 * @return array<string, mixed>
 */
function openCodeSessionPayload(): array
{
    return [
        'id' => 'sess_feature_a',
        'title' => 'feature-a',
        'directory' => '/srv/demo/.worktrees/feature-a',
    ];
}

final class OpenCodeWorkspaceDriverTestShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final readonly class OpenCodeWorkspaceDriverTestClientFactory implements OpenCodeClientFactory
{
    public function __construct(
        private OpenCode $client,
    ) {}

    public function forApp(App $app): OpenCode
    {
        return $this->client;
    }
}
