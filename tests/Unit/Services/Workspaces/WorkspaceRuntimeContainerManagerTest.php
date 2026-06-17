<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Workspaces\WorkspaceRuntimeArtifactRemovalOutcome;
use App\Enums\Workspaces\WorkspaceRuntimeContainerApplyOutcome;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainerApplyException;
use App\Services\Workspaces\WorkspaceRuntimeContainerManager;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use App\Services\Workspaces\WorkspaceRuntimeImageUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function workspaceAndNodeForManagerTest(): array
{
    $node = Node::factory()->create(['user' => 'orbit']);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'demo',
        'path' => '/home/orbit/apps/demo',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature-a',
        'path' => '/home/orbit/apps/demo/.worktrees/feature-a',
        'php_version' => null,
    ]);
    $workspace->setRelation('app', $app);

    return [$workspace, $node];
}

function renderTestWorkspaceContainer(Workspace $workspace): WorkspaceRuntimeContainer
{
    return (new WorkspaceRuntimeContainerRenderer(
        new PhpRuntimePolicy(new PhpRuntimeCatalog),
        new OrbitContainerNames,
    ))->render($workspace);
}

function inspectPayloadForWorkspace(WorkspaceRuntimeContainer $container, bool $running = true, ?string $specHash = null): string
{
    return json_encode([
        'State' => [
            'Running' => $running,
        ],
        'Config' => [
            'Labels' => [
                WorkspaceRuntimeContainer::SpecHashLabel => $specHash ?? $container->specHash(),
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

final class WorkspaceRuntimeRecordingShell implements RemoteShell
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<RemoteShellResult> */
    public array $responses = [];

    public function __construct(RemoteShellResult ...$responses)
    {
        $this->responses = $responses;
    }

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = ['node' => $node, 'script' => $script, 'options' => $options];

        return array_shift($this->responses) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

it('creates the orbit network, writes php.ini, and runs the workspace runtime container when none exists', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        // network inspect (missing) + network create
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: absent
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        // image inspect succeeds (image present on node)
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        // create script
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($scripts[0])->toContain('docker network inspect')
        ->and($scripts[1])->toContain('docker network create')
        ->and($scripts[2])->toContain('docker container inspect')
        ->and($scripts[3])->toContain("docker image inspect 'dunglas/frankenphp:1-php8.5-bookworm'")
        ->and($scripts[4])->toContain('/etc/orbit/workspaces/demo-feature-a.ini')
        ->and($scripts[4])->toContain('docker run -d')
        ->and($scripts[4])->toContain("'orbit-ws-demo-feature-a'")
        ->and($scripts[4])->toContain("'dunglas/frankenphp:1-php8.5-bookworm'");
});

it('creates the app-dev packages bind mount source before running the workspace runtime container', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl']);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'nckrtl',
        'path' => '/home/nckrtl/apps/nckrtl',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/nckrtl/.worktrees/feature-a',
        'php_version' => null,
    ]);
    $workspace->setRelation('app', $app);
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $script = $shell->calls[3]['script'];

    expect($script)->toContain("sudo install -d -m 0775 -o 'nckrtl' -g 'nckrtl' '/home/nckrtl/packages'")
        ->and($script)->toContain("--mount 'type=bind,source=/home/nckrtl/packages,target=/packages'")
        ->and(strpos($script, "sudo install -d -m 0775 -o 'nckrtl' -g 'nckrtl' '/home/nckrtl/packages'"))
        ->toBeLessThan(strpos($script, 'docker run -d'));
});

it('creates inherited configured runtime mount sources before running the workspace runtime container', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl']);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'nckrtl',
        'path' => '/home/nckrtl/apps/nckrtl',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);
    $app->runtimeMounts()->create([
        'source' => '/home/nckrtl/packages',
        'target' => '/home/nckrtl/packages',
        'read_only' => true,
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/nckrtl/.worktrees/feature-a',
        'php_version' => null,
    ]);
    $workspace->setRelation('app', $app);
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $script = $shell->calls[3]['script'];

    expect($script)->toContain("sudo install -d -m 0775 -o 'nckrtl' -g 'nckrtl' '/home/nckrtl/packages'")
        ->and($script)->toContain("--mount 'type=bind,source=/home/nckrtl/packages,target=/home/nckrtl/packages,readonly'")
        ->and(strpos($script, "sudo install -d -m 0775 -o 'nckrtl' -g 'nckrtl' '/home/nckrtl/packages'"))
        ->toBeLessThan(strpos($script, 'docker run -d'));
});

it('rejects unsafe app-dev packages bind mount sources before running the workspace runtime container', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = new WorkspaceRuntimeContainer(
        name: 'orbit-ws-demo-feature-a',
        image: 'dunglas/frankenphp:1-php8.5-bookworm',
        network: 'orbit-network',
        restartPolicy: 'unless-stopped',
        appSlug: $workspace->app->name,
        workspaceSlug: $workspace->name,
        environment: ['SERVER_NAME' => ':80'],
        mounts: [
            [
                'source' => '/etc/orbit/workspaces/demo-feature-a.ini',
                'target' => WorkspaceRuntimeContainer::PhpIniMountTarget,
                'read_only' => true,
            ],
            [
                'source' => '/home/../packages',
                'target' => '/packages',
                'read_only' => false,
            ],
        ],
        networkAliases: ['orbit-ws-demo-feature-a'],
        phpIni: ['memory_limit' => '256M'],
    );

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
    );

    expect(fn () => (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container))
        ->toThrow(WorkspaceRuntimeContainerApplyException::class, 'unsafe packages mount source');

    expect(collect($shell->calls)->contains(
        fn (array $call): bool => str_contains($call['script'], 'docker run -d')
    ))->toBeFalse();
});

it('verifies image presence on the matching-running ("Unchanged") path before returning healthy', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForWorkspace($container), stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
    );

    $outcome = (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($outcome)->toBe(WorkspaceRuntimeContainerApplyOutcome::Unchanged)
        ->and(count($scripts))->toBe(3);

    foreach ($scripts as $script) {
        expect($script)->not->toContain('docker run -d')
            ->and($script)->not->toContain('docker rm -f')
            ->and($script)->not->toContain('docker start');
    }
});

it('verifies image presence on the matching-stopped ("Started") path before starting the container', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForWorkspace($container, running: false), stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $outcome = (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($outcome)->toBe(WorkspaceRuntimeContainerApplyOutcome::Started)
        ->and($scripts[3])->toContain('docker start')
        ->and($scripts[3])->toContain("'orbit-ws-demo-feature-a'");
});

it('recreates the container when the rendered spec drifts', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForWorkspace($container, specHash: 'stale'), stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($scripts[3])->toContain('docker rm -f')
        ->and($scripts[4])->toContain('docker run -d');
});

it('returns AlreadyAbsent when removing a workspace container that does not exist on the node', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Error: No such object: orbit-ws-demo-feature-a', durationMs: 1),
    );

    $outcome = (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->remove($node, 'demo', 'feature-a');

    expect($outcome)->toBe(WorkspaceRuntimeArtifactRemovalOutcome::AlreadyAbsent)
        ->and(count($shell->calls))->toBe(1)
        ->and($shell->calls[0]['script'])->toContain('docker container inspect');
});

it('returns Removed when an existing workspace container is removed', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForWorkspace($container), stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $outcome = (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->remove($node, 'demo', 'feature-a');

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($outcome)->toBe(WorkspaceRuntimeArtifactRemovalOutcome::Removed)
        ->and($scripts[1])->toContain('docker rm -f')
        ->and($scripts[1])->toContain("'orbit-ws-demo-feature-a'");
});

it('returns FailedRemaining when an existing workspace container cannot be removed', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForWorkspace($container), stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'container in use', durationMs: 1),
    );

    $outcome = (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->remove($node, 'demo', 'feature-a');

    expect($outcome)->toBe(WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining);
});

it('returns tri-state outcomes for managed workspace runtime config file removal', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();

    $absentShell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:absent\n", stderr: '', durationMs: 1),
    );
    $absentOutcome = (new WorkspaceRuntimeContainerManager($absentShell, new DockerCommandBuilder))->removeRuntimeConfigFile($node, 'demo', 'feature-a');

    $removedShell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:present\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:absent\n", stderr: '', durationMs: 1),
    );
    $removedOutcome = (new WorkspaceRuntimeContainerManager($removedShell, new DockerCommandBuilder))->removeRuntimeConfigFile($node, 'demo', 'feature-a');

    $failedShell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:present\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
    );
    $failedOutcome = (new WorkspaceRuntimeContainerManager($failedShell, new DockerCommandBuilder))->removeRuntimeConfigFile($node, 'demo', 'feature-a');

    expect($absentOutcome)->toBe(WorkspaceRuntimeArtifactRemovalOutcome::AlreadyAbsent)
        ->and($absentShell->calls[0]['script'])->toContain("sudo test -e '/etc/orbit/workspaces/demo-feature-a.ini'")
        ->and($removedOutcome)->toBe(WorkspaceRuntimeArtifactRemovalOutcome::Removed)
        ->and($removedShell->calls[1]['script'])->toContain("sudo rm -f '/etc/orbit/workspaces/demo-feature-a.ini'")
        ->and($failedOutcome)->toBe(WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining);
});

it('returns FailedRemaining when the docker container inspect probe fails for an unknown reason instead of reporting AlreadyAbsent', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Cannot connect to the Docker daemon', durationMs: 1),
    );

    $outcome = (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->remove($node, 'demo', 'feature-a');

    expect($outcome)->toBe(WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining);
});

it('writes the managed workspace runtime config file via writeRuntimeConfigFile', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->writeRuntimeConfigFile($node, $container);

    expect($shell->calls)->toHaveCount(1)
        ->and($shell->calls[0]['script'])->toContain('/etc/orbit/workspaces/demo-feature-a.ini')
        ->and($shell->calls[0]['script'])->toContain('base64 -d');
});

it('throws WorkspaceRuntimeImageUnavailableException when the selected FrankenPHP image is not on the node before creating a new container', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Error: No such image: dunglas/frankenphp:1-php8.5-bookworm', durationMs: 1),
    );

    expect(fn () => (new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container))
        ->toThrow(WorkspaceRuntimeImageUnavailableException::class);
});

it('throws WorkspaceRuntimeContainerApplyException — NOT WorkspaceRuntimeImageUnavailableException — when the image probe fails for an unknown Docker error', function (): void {
    [$workspace, $node] = workspaceAndNodeForManagerTest();
    $container = renderTestWorkspaceContainer($workspace);

    $shell = new WorkspaceRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock.', durationMs: 1),
    );

    $manager = new WorkspaceRuntimeContainerManager($shell, new DockerCommandBuilder);

    $caught = null;
    try {
        $manager->apply($node, $container);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(WorkspaceRuntimeContainerApplyException::class)
        ->and($caught)->not->toBeInstanceOf(WorkspaceRuntimeImageUnavailableException::class)
        ->and($caught->hadExistingContainer)->toBeFalse();

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker run -d')))->toBeFalse()
        ->and(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker rm -f')))->toBeFalse()
        ->and(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker start')))->toBeFalse();
});
