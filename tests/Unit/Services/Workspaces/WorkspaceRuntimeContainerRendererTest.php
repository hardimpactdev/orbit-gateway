<?php

declare(strict_types=1);

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function makePhpWorkspace(array $appOverrides = [], array $workspaceOverrides = []): Workspace
{
    $node = createTestAppHostNode(['user' => 'orbit']);

    $app = App::factory()->for($node, 'node')->create(array_merge([
        'name' => 'demo',
        'path' => '/home/orbit/apps/demo',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ], $appOverrides));

    $workspace = Workspace::factory()->for($app, 'app')->create(array_merge([
        'name' => 'feature-a',
        'path' => '/home/orbit/apps/demo/.worktrees/feature-a',
        'php_version' => null,
    ], $workspaceOverrides));

    $workspace->setRelation('app', $app);

    return $workspace;
}

function workspaceRendererForTest(): WorkspaceRuntimeContainerRenderer
{
    return new WorkspaceRuntimeContainerRenderer(
        new PhpRuntimePolicy(new PhpRuntimeCatalog),
        new OrbitContainerNames,
    );
}

it('renders a FrankenPHP workspace runtime container for a PHP workspace with deterministic name, image, network, and source mount', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->name())->toBe('orbit-ws-demo-feature-a')
        ->and($container->image())->toBe('dunglas/frankenphp:1-php8.5-bookworm')
        ->and($container->network())->toBe('orbit-network')
        ->and($container->restartPolicy())->toBe('unless-stopped')
        ->and($container->networkAliases())->toContain('orbit-ws-demo-feature-a')
        ->and($container->networkAliases())->toContain('ws-demo-feature-a')
        ->and($container->mounts())->toContain([
            'source' => '/home/orbit/apps/demo/.worktrees/feature-a',
            'target' => '/app',
            'read_only' => false,
        ]);
});

it('mounts the owning app-dev node user packages directory at /packages', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl']);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'nckrtl',
        'path' => '/home/nckrtl/apps/nckrtl',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature-a',
        'path' => '/home/nckrtl/apps/nckrtl/.worktrees/feature-a',
        'php_version' => null,
    ]);

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->mounts())->toContain([
        'source' => '/home/nckrtl/packages',
        'target' => '/packages',
        'read_only' => false,
    ]);
});

it('inherits configured app runtime mounts from the parent app', function (): void {
    $node = createTestAppHostNode(['user' => 'nckrtl']);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'nckrtl',
        'path' => '/home/nckrtl/apps/nckrtl',
        'document_root' => 'public',
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

    $mounts = workspaceRendererForTest()->render($workspace)->mounts();

    expect($mounts)->toContain([
        'source' => '/home/nckrtl/packages',
        'target' => '/packages',
        'read_only' => false,
    ])->and($mounts)->toContain([
        'source' => '/home/nckrtl/packages',
        'target' => '/home/nckrtl/packages',
        'read_only' => true,
    ]);
});

it('does not mount the packages directory for app-prod PHP workspace runtimes', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'demo-prod',
        'environment' => 'production',
        'path' => '/home/demo/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature-a',
        'path' => '/home/demo/app/.worktrees/feature-a',
        'php_version' => null,
    ]);

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->mounts())->not->toContain([
        'source' => '/home/orbit/packages',
        'target' => '/packages',
        'read_only' => false,
    ]);
});

it('uses the workspace php_version override when set', function (): void {
    $workspace = makePhpWorkspace(workspaceOverrides: ['php_version' => '8.4']);

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->image())->toBe('dunglas/frankenphp:1-php8.4-bookworm');
});

it('inherits the app php_version when workspace php_version is null', function (): void {
    $workspace = makePhpWorkspace(appOverrides: ['php_version' => '8.4'], workspaceOverrides: ['php_version' => null]);

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->image())->toBe('dunglas/frankenphp:1-php8.4-bookworm');
});

it('uses the approved glibc-based FrankenPHP image family rather than alpine/musl', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->image())->toEndWith('-bookworm')
        ->and($container->image())->not->toContain('alpine')
        ->and($container->image())->not->toContain('musl');
});

it('does not render a workspace runtime container for static (non-PHP) workspaces', function (): void {
    $workspace = makePhpWorkspace(appOverrides: ['runtime_kind' => AppRuntimeKind::Static]);

    expect(fn () => workspaceRendererForTest()->render($workspace))
        ->toThrow(InvalidArgumentException::class);
});

it('changes the spec hash when php_version changes so the manager recreates the container', function (): void {
    $renderer = workspaceRendererForTest();

    $a = $renderer->render(makePhpWorkspace(appOverrides: ['name' => 'a'], workspaceOverrides: ['name' => 'feature-a', 'php_version' => '8.5']));
    $b = $renderer->render(makePhpWorkspace(appOverrides: ['name' => 'b'], workspaceOverrides: ['name' => 'feature-b', 'php_version' => '8.4']));

    expect($a->specHash())->not->toBe($b->specHash());
});

it('changes the spec hash when the app-dev packages mount policy changes', function (): void {
    $renderer = workspaceRendererForTest();
    $workspace = makePhpWorkspace();

    $withPackagesMount = $renderer->render($workspace)->specHash();
    $app = $workspace->app;
    assert($app instanceof App);
    $node = $app->node;
    assert($node instanceof Node);

    $node->roleAssignments()->update(['status' => 'pending']);
    $node->unsetRelation('roleAssignments');
    $app->unsetRelation('node');
    $workspace->unsetRelation('app');

    expect($withPackagesMount)->not->toBe($renderer->render($workspace)->specHash());
});

it('changes the spec hash when configured parent app runtime mounts change', function (): void {
    $renderer = workspaceRendererForTest();
    $workspace = makePhpWorkspace();

    $withoutConfiguredMount = $renderer->render($workspace)->specHash();
    $app = $workspace->app;
    assert($app instanceof App);

    $app->runtimeMounts()->create([
        'source' => '/home/orbit/packages',
        'target' => '/home/orbit/packages',
        'read_only' => true,
    ]);
    $app->unsetRelation('runtimeMounts');
    $workspace->unsetRelation('app');

    expect($withoutConfiguredMount)->not->toBe($renderer->render($workspace)->specHash());
});

it('renders opcache directives from the PHP runtime policy', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->phpIni())->toMatchArray([
        'opcache.enable' => '1',
        'opcache.enable_cli' => '1',
        'opcache.memory_consumption' => '256',
        'opcache.max_accelerated_files' => '20000',
    ]);
});

it('renders realpath cache directives from the PHP runtime policy', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->phpIni())->toMatchArray([
        'realpath_cache_size' => '4096K',
        'realpath_cache_ttl' => '600',
    ]);
});

it('renders app-dev FrankenPHP thread pool settings for classic workspace runtimes', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->environment())->toMatchArray([
        'FRANKENPHP_CONFIG' => "max_threads auto\nmax_idle_time 1h",
    ]);
});

it('does not render app-dev FrankenPHP thread pool settings for app-prod workspace runtimes', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'demo-prod',
        'environment' => 'production',
        'path' => '/home/demo/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'name' => 'feature-a',
        'path' => '/home/demo/app/.worktrees/feature-a',
        'php_version' => null,
    ]);

    $container = workspaceRendererForTest()->render($workspace);

    expect(array_key_exists('FRANKENPHP_CONFIG', $container->environment()))->toBeFalse();
});

it('omits opcache.preload from rendered php ini when the workspace has no preload script', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect(array_key_exists('opcache.preload', $container->phpIni()))->toBeFalse();
});

it('renders opcache.preload when a preload script is provided', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace, preloadPath: '/app/bootstrap/cache/preload.php');

    expect($container->phpIni()['opcache.preload'])->toBe('/app/bootstrap/cache/preload.php');
});

it('renders FrankenPHP-consumed SERVER_NAME and SERVER_ROOT so the configured root is actually served', function (): void {
    $renderer = workspaceRendererForTest();

    $publicRoot = $renderer->render(makePhpWorkspace(appOverrides: ['name' => 'a', 'document_root' => 'public'], workspaceOverrides: ['name' => 'feature-a']));
    $webRoot = $renderer->render(makePhpWorkspace(appOverrides: ['name' => 'b', 'document_root' => 'web'], workspaceOverrides: ['name' => 'feature-b']));
    $projectRoot = $renderer->render(makePhpWorkspace(appOverrides: ['name' => 'c', 'document_root' => '.'], workspaceOverrides: ['name' => 'feature-c']));

    expect($publicRoot->environment())->toMatchArray([
        'SERVER_NAME' => ':80',
        'SERVER_ROOT' => '/app/public',
        'ORBIT_APP_DOCUMENT_ROOT' => 'public',
    ])
        ->and($webRoot->environment())->toMatchArray([
            'SERVER_NAME' => ':80',
            'SERVER_ROOT' => '/app/web',
        ])
        ->and($projectRoot->environment())->toMatchArray([
            'SERVER_NAME' => ':80',
            'SERVER_ROOT' => '/app',
        ])
        ->and($publicRoot->specHash())->not->toBe($webRoot->specHash())
        ->and($publicRoot->specHash())->not->toBe($projectRoot->specHash());
});

it('exposes ORBIT_APP, ORBIT_WORKSPACE, and ORBIT_PHP_VERSION env to the container', function (): void {
    $workspace = makePhpWorkspace(appOverrides: ['php_version' => '8.4'], workspaceOverrides: ['php_version' => '8.5']);

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->environment())->toMatchArray([
        'ORBIT_APP' => 'demo',
        'ORBIT_WORKSPACE' => 'feature-a',
        'ORBIT_PHP_VERSION' => '8.5',
    ]);
});

it('exposes the document-root env on the rendered docker run command so the configured root reaches FrankenPHP', function (): void {
    $workspace = makePhpWorkspace(appOverrides: ['document_root' => 'web']);
    $container = workspaceRendererForTest()->render($workspace);

    $command = (new DockerCommandBuilder)->runDetached($container);

    expect($command)->toContain("--env 'SERVER_NAME=:80'")
        ->and($command)->toContain("--env 'SERVER_ROOT=/app/web'");
});

it('exposes labels with the spec hash so the manager can detect drift', function (): void {
    $workspace = makePhpWorkspace();

    $container = workspaceRendererForTest()->render($workspace);

    expect($container->labels())->toMatchArray([
        'orbit.managed' => 'true',
        'orbit.container.kind' => 'workspace-runtime',
        'orbit.app' => 'demo',
        'orbit.workspace' => 'feature-a',
    ])
        ->and($container->labels()['orbit.workspace.spec_hash'] ?? null)->toBe($container->specHash());
});
