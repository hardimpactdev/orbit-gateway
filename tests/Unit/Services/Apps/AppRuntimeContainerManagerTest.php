<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeArtifactRemovalOutcome;
use App\Enums\Apps\AppRuntimeContainerApplyOutcome;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerApplyException;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\AppRuntimeImageUnavailableException;
use App\Services\Apps\AppRuntimeUserUnavailableException;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function appAndNodeForManagerTest(): array
{
    $node = Node::factory()->create(['user' => 'orbit']);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    return [$app, $node];
}

function productionAppAndNodeForManagerTest(): array
{
    $node = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'environment' => 'production',
        'path' => '/home/docs/app',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    return [$app, $node];
}

function renderTestAppContainer(App $app): AppRuntimeContainer
{
    return (new AppRuntimeContainerRenderer(
        new PhpRuntimePolicy(new PhpRuntimeCatalog),
        new OrbitContainerNames,
    ))->render($app);
}

function inspectPayloadForApp(AppRuntimeContainer $container, bool $running = true, ?string $specHash = null): string
{
    return json_encode([
        'State' => [
            'Running' => $running,
        ],
        'Config' => [
            'Labels' => [
                AppRuntimeContainer::SpecHashLabel => $specHash ?? $container->specHash(),
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

final class AppRuntimeRecordingShell implements RemoteShell
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

it('creates the orbit network, writes php.ini, and runs the app runtime container when none exists', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
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

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($outcome)->toBe(AppRuntimeContainerApplyOutcome::Created)
        ->and($scripts[0])->toContain('docker network inspect')
        ->and($scripts[1])->toContain('docker network create')
        ->and($scripts[2])->toContain('docker container inspect')
        ->and($scripts[3])->toContain("docker image inspect 'dunglas/frankenphp:1-php8.5-bookworm'")
        ->and($scripts[4])->toContain('/etc/orbit/apps/docs.ini')
        ->and($scripts[4])->toContain("sudo install -d -m 0775 '/home/orbit/apps/docs/.orbit/frankenphp/data'")
        ->and($scripts[4])->toContain("sudo install -d -m 0775 '/home/orbit/apps/docs/.orbit/frankenphp/config'")
        ->and($scripts[4])->toContain('docker run -d')
        ->and($scripts[4])->toContain("--env 'SERVER_NAME=:8080'")
        ->and($scripts[4])->toContain("--env 'XDG_CONFIG_HOME=/config'")
        ->and($scripts[4])->toContain("--env 'XDG_DATA_HOME=/data'")
        ->and($scripts[4])->not->toContain(' --publish ')
        ->and($scripts[4])->toContain("'orbit-app-docs'")
        ->and($scripts[4])->toContain("'dunglas/frankenphp:1-php8.5-bookworm'");
});

it('creates the app-dev packages bind mount source before running the app runtime container', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl']);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'nckrtl',
        'path' => '/home/nckrtl/apps/nckrtl',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $script = $shell->calls[3]['script'];

    expect($script)->toContain("sudo install -d -m 0775 -o 'nckrtl' -g 'nckrtl' '/home/nckrtl/packages'")
        ->and($script)->toContain("--mount 'type=bind,source=/home/nckrtl/packages,target=/packages'")
        ->and(strpos($script, "sudo install -d -m 0775 -o 'nckrtl' -g 'nckrtl' '/home/nckrtl/packages'"))
        ->toBeLessThan(strpos($script, 'docker run -d'));
});

it('creates configured runtime mount sources before running the app runtime container', function (): void {
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
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $script = $shell->calls[3]['script'];

    expect($script)->toContain("sudo install -d -m 0775 -o 'nckrtl' -g 'nckrtl' '/home/nckrtl/packages'")
        ->and($script)->toContain("--mount 'type=bind,source=/home/nckrtl/packages,target=/home/nckrtl/packages,readonly'")
        ->and(strpos($script, "sudo install -d -m 0775 -o 'nckrtl' -g 'nckrtl' '/home/nckrtl/packages'"))
        ->toBeLessThan(strpos($script, 'docker run -d'));
});

it('rejects unsafe app-dev packages bind mount sources before running the app runtime container', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = new AppRuntimeContainer(
        name: 'orbit-app-docs',
        image: 'dunglas/frankenphp:1-php8.5-bookworm',
        network: 'orbit-network',
        restartPolicy: 'unless-stopped',
        appSlug: $app->name,
        runtimeUser: null,
        environment: ['SERVER_NAME' => ':8080'],
        mounts: [
            [
                'source' => '/etc/orbit/apps/docs.ini',
                'target' => AppRuntimeContainer::PhpIniMountTarget,
                'read_only' => true,
            ],
            [
                'source' => '/home/../packages',
                'target' => '/packages',
                'read_only' => false,
            ],
        ],
        networkAliases: ['orbit-app-docs'],
        phpIni: ['memory_limit' => '256M'],
    );

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
    );

    expect(fn () => (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container))
        ->toThrow(AppRuntimeContainerApplyException::class, 'unsafe packages mount source');

    expect(collect($shell->calls)->contains(
        fn (array $call): bool => str_contains($call['script'], 'docker run -d')
    ))->toBeFalse();
});

it('resolves production runtime users to numeric uid gid before creating the container', function (): void {
    [$app, $node] = productionAppAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        // network inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: absent
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        // image inspect succeeds
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        // runtime user uid/gid resolution
        new RemoteShellResult(exitCode: 0, stdout: "1001\n1002\n", stderr: '', durationMs: 1),
        // create script
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($outcome)->toBe(AppRuntimeContainerApplyOutcome::Created)
        ->and($container->runtimeUser())->toBe('docs')
        ->and($scripts[3])->toContain("id -u 'docs'")
        ->and($scripts[3])->toContain("id -g 'docs'")
        ->and($scripts[4])->toContain("sudo chown '1001:1002' '/home/docs/app/.orbit/frankenphp/data'")
        ->and($scripts[4])->toContain("sudo chown '1001:1002' '/home/docs/app/.orbit/frankenphp/config'")
        ->and($scripts[4])->toContain("--user '1001:1002'")
        ->and($scripts[4])->not->toContain('/var/run/docker.sock')
        ->and($scripts[4])->not->toContain('--group-add');
});

it('throws a runtime-user exception before creating the container when the production runtime user is missing', function (): void {
    [$app, $node] = productionAppAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'id: docs: no such user', durationMs: 1),
    );

    expect(fn () => (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container))
        ->toThrow(AppRuntimeUserUnavailableException::class);

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect(collect($scripts)->contains(fn (string $script): bool => str_contains($script, 'docker run -d')))->toBeFalse();
});

it('verifies image presence on the matching-running ("Unchanged") path before returning healthy', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        // network inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: matches and running
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForApp($container), stderr: '', durationMs: 1),
        // image inspect succeeds (image still present on node)
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
    );

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($outcome)->toBe(AppRuntimeContainerApplyOutcome::Unchanged)
        ->and(count($scripts))->toBe(3)
        ->and($scripts[0])->toContain('docker network inspect')
        ->and($scripts[1])->toContain('docker container inspect')
        ->and($scripts[2])->toContain("docker image inspect 'dunglas/frankenphp:1-php8.5-bookworm'");

    foreach ($scripts as $script) {
        expect($script)->not->toContain('docker run -d')
            ->and($script)->not->toContain('docker rm -f')
            ->and($script)->not->toContain('docker start');
    }
});

it('verifies image presence on the matching-stopped ("Started") path before starting the container', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        // network inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: matches but stopped
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForApp($container, running: false), stderr: '', durationMs: 1),
        // image inspect succeeds (image still present on node)
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        // docker start
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($outcome)->toBe(AppRuntimeContainerApplyOutcome::Started)
        ->and($scripts[1])->toContain('docker container inspect')
        ->and($scripts[2])->toContain("docker image inspect 'dunglas/frankenphp:1-php8.5-bookworm'")
        ->and($scripts[3])->toContain('docker start')
        ->and($scripts[3])->toContain("'orbit-app-docs'");
});

it('recreates the container when the rendered spec drifts', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForApp($container, specHash: 'stale'), stderr: '', durationMs: 1),
        // image inspect succeeds (image present on node)
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($outcome)->toBe(AppRuntimeContainerApplyOutcome::Recreated)
        ->and($scripts[1])->toContain('docker container inspect')
        ->and($scripts[2])->toContain("docker image inspect 'dunglas/frankenphp:1-php8.5-bookworm'")
        ->and($scripts[3])->toContain('docker rm -f')
        ->and($scripts[4])->toContain('docker run -d')
        ->and($scripts[4])->toContain("--env 'SERVER_NAME=:8080'")
        ->and($scripts[4])->not->toContain(' --publish ');
});

it('returns AlreadyAbsent when removing a container that does not exist on the node', function (): void {
    [$_, $node] = appAndNodeForManagerTest();

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'no such container', durationMs: 1),
    );

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->remove($node, 'docs');

    expect($outcome)->toBe(AppRuntimeArtifactRemovalOutcome::AlreadyAbsent)
        ->and(count($shell->calls))->toBe(1)
        ->and($shell->calls[0]['script'])->toContain('docker container inspect');
});

it('returns Removed when an existing container is removed', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForApp($container), stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->remove($node, 'docs');

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($outcome)->toBe(AppRuntimeArtifactRemovalOutcome::Removed)
        ->and($scripts[0])->toContain('docker container inspect')
        ->and($scripts[1])->toContain('docker rm -f')
        ->and($scripts[1])->toContain("'orbit-app-docs'");
});

it('returns FailedRemaining when an existing container cannot be removed', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForApp($container), stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'container in use', durationMs: 1),
    );

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->remove($node, 'docs');

    expect($outcome)->toBe(AppRuntimeArtifactRemovalOutcome::FailedRemaining);
});

it('returns tri-state outcomes for managed runtime config file removal', function (): void {
    [$_, $node] = appAndNodeForManagerTest();

    $absentShell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:absent\n", stderr: '', durationMs: 1),
    );
    $absentOutcome = (new AppRuntimeContainerManager($absentShell, new DockerCommandBuilder))->removeRuntimeConfigFile($node, 'docs');

    $removedShell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:present\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:absent\n", stderr: '', durationMs: 1),
    );
    $removedOutcome = (new AppRuntimeContainerManager($removedShell, new DockerCommandBuilder))->removeRuntimeConfigFile($node, 'docs');

    $failedShell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:present\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
    );
    $failedOutcome = (new AppRuntimeContainerManager($failedShell, new DockerCommandBuilder))->removeRuntimeConfigFile($node, 'docs');

    expect($absentOutcome)->toBe(AppRuntimeArtifactRemovalOutcome::AlreadyAbsent)
        ->and($absentShell->calls)->toHaveCount(1)
        ->and($absentShell->calls[0]['script'])->toContain("sudo test -e '/etc/orbit/apps/docs.ini'")
        ->and($absentShell->calls[0]['script'])->toContain('orbit-container-config-probe:absent')
        ->and($removedOutcome)->toBe(AppRuntimeArtifactRemovalOutcome::Removed)
        ->and($removedShell->calls[1]['script'])->toContain("sudo rm -f '/etc/orbit/apps/docs.ini'")
        ->and($failedOutcome)->toBe(AppRuntimeArtifactRemovalOutcome::FailedRemaining);
});

it('returns FailedRemaining when the docker container inspect probe fails for an unknown reason instead of reporting AlreadyAbsent', function (): void {
    [$_, $node] = appAndNodeForManagerTest();

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?',
            durationMs: 1,
        ),
    );

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->remove($node, 'docs');

    expect($outcome)->toBe(AppRuntimeArtifactRemovalOutcome::FailedRemaining)
        ->and(count($shell->calls))->toBe(1)
        ->and($shell->calls[0]['script'])->toContain('docker container inspect');
});

it('returns AlreadyAbsent only when docker inspect reports "No such object"', function (): void {
    [$_, $node] = appAndNodeForManagerTest();

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Error response from daemon: No such object: orbit-app-docs', durationMs: 1),
    );

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->remove($node, 'docs');

    expect($outcome)->toBe(AppRuntimeArtifactRemovalOutcome::AlreadyAbsent)
        ->and(count($shell->calls))->toBe(1);
});

it('returns FailedRemaining when the sudo runtime config probe fails for an unknown reason', function (): void {
    [$_, $node] = appAndNodeForManagerTest();

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:error\n", stderr: 'sudo: a terminal is required to read the password', durationMs: 1),
    );

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->removeRuntimeConfigFile($node, 'docs');

    expect($outcome)->toBe(AppRuntimeArtifactRemovalOutcome::FailedRemaining)
        ->and(count($shell->calls))->toBe(1)
        ->and($shell->calls[0]['script'])->toContain("sudo test -e '/etc/orbit/apps/docs.ini'");
});

it('returns FailedRemaining when the runtime config probe shell call itself fails (SSH or remote error)', function (): void {
    [$_, $node] = appAndNodeForManagerTest();

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'ssh: connect to host: connection refused', durationMs: 1),
    );

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->removeRuntimeConfigFile($node, 'docs');

    expect($outcome)->toBe(AppRuntimeArtifactRemovalOutcome::FailedRemaining);
});

it('returns FailedRemaining when post-removal probe cannot confirm the runtime config file is gone', function (): void {
    [$_, $node] = appAndNodeForManagerTest();

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:present\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:error\n", stderr: '', durationMs: 1),
    );

    $outcome = (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->removeRuntimeConfigFile($node, 'docs');

    expect($outcome)->toBe(AppRuntimeArtifactRemovalOutcome::FailedRemaining);
});

it('writes the managed runtime config file via writeRuntimeConfigFile', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->writeRuntimeConfigFile($node, $container);

    expect($shell->calls)->toHaveCount(1)
        ->and($shell->calls[0]['script'])->toContain('/etc/orbit/apps/docs.ini')
        ->and($shell->calls[0]['script'])->toContain('base64 -d');
});

it('throws AppRuntimeImageUnavailableException when the selected FrankenPHP image is not on the node before creating a new container', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        // network inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: absent
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        // image inspect: definite "No such image"
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Error: No such image: dunglas/frankenphp:1-php8.5-bookworm', durationMs: 1),
    );

    expect(fn () => (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container))
        ->toThrow(AppRuntimeImageUnavailableException::class);
});

it('throws AppRuntimeImageUnavailableException when image was pruned out from under a matching running container before returning Unchanged', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        // network inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: matches and running — but image is missing,
        // so the preflight must still throw before returning Unchanged
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForApp($container), stderr: '', durationMs: 1),
        // image inspect: definite "No such image"
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Error: No such image: dunglas/frankenphp:1-php8.5-bookworm', durationMs: 1),
    );

    $caughtImage = '';
    try {
        (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container);
    } catch (AppRuntimeImageUnavailableException $exception) {
        $caughtImage = $exception->image;
    }

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect($caughtImage)->toBe('dunglas/frankenphp:1-php8.5-bookworm')
        // Must throw before any container mutation.
        ->and(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker start')))->toBeFalse()
        ->and(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker rm -f')))->toBeFalse()
        ->and(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker run -d')))->toBeFalse();
});

it('throws AppRuntimeImageUnavailableException when image was pruned out from under a matching stopped container before starting it', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForApp($container, running: false), stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Error: No such image: dunglas/frankenphp:1-php8.5-bookworm', durationMs: 1),
    );

    expect(fn () => (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container))
        ->toThrow(AppRuntimeImageUnavailableException::class);

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker start')))->toBeFalse();
});

it('throws AppRuntimeImageUnavailableException when image is not on the node before recreating a drifted container', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForApp($container, specHash: 'stale'), stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Error: No such image: dunglas/frankenphp:1-php8.5-bookworm', durationMs: 1),
    );

    expect(fn () => (new AppRuntimeContainerManager($shell, new DockerCommandBuilder))->apply($node, $container))
        ->toThrow(AppRuntimeImageUnavailableException::class);

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker rm -f')))->toBeFalse()
        ->and(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker run -d')))->toBeFalse();
});

it('throws AppRuntimeContainerApplyException — NOT AppRuntimeImageUnavailableException — when the image probe fails for an unknown Docker error and the container is absent', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: absent
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        // image inspect: unknown daemon failure (not "No such image")
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock.', durationMs: 1),
    );

    $manager = new AppRuntimeContainerManager($shell, new DockerCommandBuilder);

    $caught = null;
    try {
        $manager->apply($node, $container);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AppRuntimeContainerApplyException::class)
        ->and($caught)->not->toBeInstanceOf(AppRuntimeImageUnavailableException::class)
        ->and($caught->hadExistingContainer)->toBeFalse();

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    // The unknown failure must abort before any container mutation.
    expect(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker run -d')))->toBeFalse()
        ->and(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker rm -f')))->toBeFalse()
        ->and(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker start')))->toBeFalse();
});

it('throws AppRuntimeContainerApplyException with hadExistingContainer=true when image probe fails unknown over a matching running container (must NOT return Unchanged)', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: matches and running
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForApp($container), stderr: '', durationMs: 1),
        // image inspect: unknown failure — must NOT fall through to Unchanged
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Cannot connect to the Docker daemon.', durationMs: 1),
    );

    $manager = new AppRuntimeContainerManager($shell, new DockerCommandBuilder);

    $caught = null;
    try {
        $manager->apply($node, $container);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AppRuntimeContainerApplyException::class)
        ->and($caught)->not->toBeInstanceOf(AppRuntimeImageUnavailableException::class)
        ->and($caught->hadExistingContainer)->toBeTrue();

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker start')))->toBeFalse()
        ->and(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker rm -f')))->toBeFalse()
        ->and(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker run -d')))->toBeFalse();
});

it('throws AppRuntimeContainerApplyException with hadExistingContainer=true when image probe fails unknown over a matching stopped container (must NOT return Started)', function (): void {
    [$app, $node] = appAndNodeForManagerTest();
    $container = renderTestAppContainer($app);

    $shell = new AppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: matches but stopped
        new RemoteShellResult(exitCode: 0, stdout: inspectPayloadForApp($container, running: false), stderr: '', durationMs: 1),
        // image inspect: unknown failure
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
    );

    $manager = new AppRuntimeContainerManager($shell, new DockerCommandBuilder);

    $caught = null;
    try {
        $manager->apply($node, $container);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(AppRuntimeContainerApplyException::class)
        ->and($caught)->not->toBeInstanceOf(AppRuntimeImageUnavailableException::class)
        ->and($caught->hadExistingContainer)->toBeTrue();

    $scripts = array_map(fn (array $call): string => $call['script'], $shell->calls);

    expect(collect($scripts)->contains(fn (string $s): bool => str_contains($s, 'docker start')))->toBeFalse();
});
