<?php

declare(strict_types=1);

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function makePhpApp(array $overrides = []): App
{
    $node = createTestAppHostNode(['user' => 'orbit']);

    return App::factory()->for($node, 'node')->create(array_merge([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ], $overrides));
}

function rendererForTest(): AppRuntimeContainerRenderer
{
    return new AppRuntimeContainerRenderer(
        new PhpRuntimePolicy(new PhpRuntimeCatalog),
        new OrbitContainerNames,
    );
}

it('renders a FrankenPHP app runtime container for a PHP app with deterministic name, image, network, and source mount', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->name())->toBe('orbit-app-docs')
        ->and($container->image())->toBe('dunglas/frankenphp:1-php8.5-bookworm')
        ->and($container->network())->toBe('orbit-network')
        ->and($container->restartPolicy())->toBe('unless-stopped')
        ->and($container->networkAliases())->toContain('orbit-app-docs')
        ->and($container->networkAliases())->toContain('app-docs')
        ->and($container->mounts())->toContain([
            'source' => '/home/orbit/apps/docs',
            'target' => '/app',
            'read_only' => false,
        ])
        ->and($container->mounts())->toContain([
            'source' => '/home/orbit/apps/docs',
            'target' => '/home/orbit/apps/docs',
            'read_only' => false,
        ])
        ->and($container->mounts())->toContain([
            'source' => '/home/orbit/apps/docs/.orbit/frankenphp/data',
            'target' => '/data',
            'read_only' => false,
        ])
        ->and($container->mounts())->toContain([
            'source' => '/home/orbit/apps/docs/.orbit/frankenphp/config',
            'target' => '/config',
            'read_only' => false,
        ])
        ->and($container->environment())->toMatchArray([
            'XDG_CONFIG_HOME' => '/config',
            'XDG_DATA_HOME' => '/data',
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

    $container = rendererForTest()->render($app);

    expect($container->mounts())->toContain([
        'source' => '/home/nckrtl/packages',
        'target' => '/packages',
        'read_only' => false,
    ]);
});

it('renders configured app runtime mounts after built-in mounts', function (): void {
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

    $mounts = rendererForTest()->render($app)->mounts();

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

it('does not mount the packages directory for app-prod PHP app runtimes', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs-prod',
        'environment' => 'production',
        'path' => '/home/docs/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $container = rendererForTest()->render($app);

    expect($container->mounts())->not->toContain([
        'source' => '/home/orbit/packages',
        'target' => '/packages',
        'read_only' => false,
    ]);
});

it('renders a production app runtime user from the app source owner but leaves development containers on the node user', function (): void {
    $productionNode = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $productionApp = App::factory()->for($productionNode, 'node')->create([
        'name' => 'docs-prod',
        'environment' => 'production',
        'path' => '/home/docs/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $developmentApp = makePhpApp([
        'name' => 'docs-dev',
        'environment' => 'development',
        'path' => '/home/docs/app',
    ]);

    $renderer = rendererForTest();

    expect($renderer->render($productionApp)->runtimeUser())->toBe('docs')
        ->and($renderer->render($developmentApp)->runtimeUser())->toBeNull();
});

it('renders the selected PHP image when php_version differs', function (): void {
    $app = makePhpApp(['php_version' => '8.4']);

    $container = rendererForTest()->render($app);

    expect($container->image())->toBe('dunglas/frankenphp:1-php8.4-bookworm');
});

it('uses the approved glibc-based FrankenPHP image family rather than alpine/musl', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->image())->toEndWith('-bookworm')
        ->and($container->image())->not->toContain('alpine')
        ->and($container->image())->not->toContain('musl');
});

it('does not render an app runtime container for static apps', function (): void {
    $app = makePhpApp(['runtime_kind' => AppRuntimeKind::Static]);

    expect(fn () => rendererForTest()->render($app))
        ->toThrow(InvalidArgumentException::class);
});

it('changes the spec hash when php_version changes so the manager recreates the container', function (): void {
    $renderer = rendererForTest();

    $php85 = $renderer->render(makePhpApp(['name' => 'a', 'php_version' => '8.5']));
    $php84 = $renderer->render(makePhpApp(['name' => 'b', 'php_version' => '8.4']));

    expect($php85->specHash())->not->toBe($php84->specHash());
});

it('changes the spec hash when the app-dev packages mount policy changes', function (): void {
    $renderer = rendererForTest();
    $node = createTestAppHostNode(['user' => 'orbit']);
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs-dev',
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $withPackagesMount = $renderer->render($app)->specHash();

    $node->roleAssignments()->update(['status' => 'pending']);
    $node->unsetRelation('roleAssignments');
    $app->unsetRelation('node');

    expect($withPackagesMount)->not->toBe($renderer->render($app)->specHash());
});

it('changes the spec hash when configured app runtime mounts change', function (): void {
    $renderer = rendererForTest();
    $app = makePhpApp(['name' => 'docs-dev']);

    $withoutConfiguredMount = $renderer->render($app)->specHash();

    $app->runtimeMounts()->create([
        'source' => '/home/orbit/packages',
        'target' => '/home/orbit/packages',
        'read_only' => true,
    ]);
    $app->unsetRelation('runtimeMounts');

    expect($withoutConfiguredMount)->not->toBe($renderer->render($app)->specHash());
});

it('renders opcache directives from the PHP runtime policy', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->phpIni())->toMatchArray([
        'opcache.enable' => '1',
        'opcache.enable_cli' => '1',
        'opcache.memory_consumption' => '256',
        'opcache.max_accelerated_files' => '20000',
    ]);
});

it('renders realpath cache directives from the PHP runtime policy', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->phpIni())->toMatchArray([
        'realpath_cache_size' => '4096K',
        'realpath_cache_ttl' => '600',
    ]);
});

it('omits opcache.preload from rendered php ini when the app has no preload script', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect(array_key_exists('opcache.preload', $container->phpIni()))->toBeFalse();
});

it('renders opcache.preload when a preload script is provided', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app, preloadPath: '/app/bootstrap/cache/preload.php');

    expect($container->phpIni()['opcache.preload'])->toBe('/app/bootstrap/cache/preload.php');
});

it('renders FrankenPHP-consumed SERVER_NAME and SERVER_ROOT so the configured root is actually served', function (): void {
    $renderer = rendererForTest();

    $publicRoot = $renderer->render(makePhpApp(['name' => 'a', 'document_root' => 'public']));
    $webRoot = $renderer->render(makePhpApp(['name' => 'b', 'document_root' => 'web']));
    $projectRoot = $renderer->render(makePhpApp(['name' => 'c', 'document_root' => '.']));

    expect($publicRoot->environment())->toMatchArray([
        'SERVER_NAME' => ':8080',
        'SERVER_ROOT' => '/app/public',
        'ORBIT_APP_DOCUMENT_ROOT' => 'public',
    ])
        ->and($webRoot->environment())->toMatchArray([
            'SERVER_NAME' => ':8080',
            'SERVER_ROOT' => '/app/web',
        ])
        ->and($projectRoot->environment())->toMatchArray([
            'SERVER_NAME' => ':8080',
            'SERVER_ROOT' => '/app',
        ])
        ->and($publicRoot->specHash())->not->toBe($webRoot->specHash())
        ->and($publicRoot->specHash())->not->toBe($projectRoot->specHash());
});

it('uses the internal app runtime upstream on port 8080', function (): void {
    $app = makePhpApp(['name' => 'docs']);

    expect(rendererForTest()->upstreamUrl($app))->toBe('http://orbit-app-docs:8080');
});

it('exposes the document-root env on the rendered docker run command so the configured root reaches FrankenPHP', function (): void {
    $app = makePhpApp(['document_root' => 'web']);
    $container = rendererForTest()->render($app);

    $command = (new DockerCommandBuilder)->runDetached($container);

    expect($command)->toContain("--env 'SERVER_NAME=:8080'")
        ->and($command)->toContain("--env 'SERVER_ROOT=/app/web'")
        ->and($command)->not->toContain(' --publish ');
});

it('exposes labels with the spec hash so the manager can detect drift', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->labels())->toMatchArray([
        'orbit.managed' => 'true',
        'orbit.container.kind' => 'app-runtime',
    ])
        ->and($container->labels()['orbit.app.spec_hash'] ?? null)->toBe($container->specHash());
});

it('does not render any worker-mode runtime config when worker_enabled is false', function (): void {
    $app = makePhpApp();

    $container = rendererForTest()->render($app);

    expect($container->environment()['FRANKENPHP_CONFIG'] ?? null)->toBe("max_threads auto\nmax_idle_time 1h")
        ->and(array_key_exists('MAX_REQUESTS', $container->environment()))->toBeFalse();
});

it('does not include any FRANKENPHP_CONFIG worker directive in the docker run command when worker mode is off', function (): void {
    $app = makePhpApp();
    $container = rendererForTest()->render($app);

    $command = (new DockerCommandBuilder)->runDetached($container);

    expect($command)->toContain("FRANKENPHP_CONFIG=max_threads auto\nmax_idle_time 1h")
        ->and($command)->not->toContain('worker /app')
        ->and($command)->not->toContain('MAX_REQUESTS');
});

it('does not render app-dev FrankenPHP thread pool settings for app-prod classic runtimes', function (): void {
    $node = createTestAppHostNode(['user' => 'orbit'], 'app-prod');
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs-prod',
        'environment' => 'production',
        'path' => '/home/docs/app',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $container = rendererForTest()->render($app);

    expect(array_key_exists('FRANKENPHP_CONFIG', $container->environment()))->toBeFalse();
});

it('renders the FrankenPHP worker block against public/frankenphp-worker.php with workers=auto', function (): void {
    $app = makePhpApp([
        'worker_enabled' => true,
        'worker_config' => [
            'workers' => 'auto',
            'max_requests' => 500,
        ],
    ]);

    $container = rendererForTest()->render($app);

    expect($container->environment())->toMatchArray([
        'FRANKENPHP_CONFIG' => "max_threads auto\nmax_idle_time 1h\nworker {\n\tfile /app/public/frankenphp-worker.php\n}",
        'MAX_REQUESTS' => '500',
    ]);
});

it('renders the block-form `worker` directive with num when worker_config.workers is an integer', function (): void {
    $app = makePhpApp([
        'worker_enabled' => true,
        'worker_config' => [
            'workers' => 4,
            'max_requests' => 1000,
        ],
    ]);

    $container = rendererForTest()->render($app);

    expect($container->environment())->toMatchArray([
        'FRANKENPHP_CONFIG' => "max_threads auto\nmax_idle_time 1h\nworker {\n\tfile /app/public/frankenphp-worker.php\n\tnum 4\n}",
        'MAX_REQUESTS' => '1000',
    ]);
});

it('does not emit any OCTANE_* or MAX_CONSECUTIVE_FAILURES env vars; FrankenPHP and Laravel only read FRANKENPHP_CONFIG and MAX_REQUESTS', function (): void {
    $app = makePhpApp([
        'worker_enabled' => true,
        'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
    ]);

    $container = rendererForTest()->render($app);

    expect(array_key_exists('OCTANE_SERVER', $container->environment()))->toBeFalse()
        ->and(array_key_exists('OCTANE_WORKERS', $container->environment()))->toBeFalse()
        ->and(array_key_exists('OCTANE_MAX_REQUESTS', $container->environment()))->toBeFalse()
        ->and(array_key_exists('OCTANE_MAX_CONSECUTIVE_FAILURES', $container->environment()))->toBeFalse()
        ->and(array_key_exists('MAX_CONSECUTIVE_FAILURES', $container->environment()))->toBeFalse();
});

it('points the worker directive at the configured document root, not always /app/public', function (): void {
    $app = makePhpApp([
        'document_root' => 'web',
        'worker_enabled' => true,
        'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
    ]);

    $container = rendererForTest()->render($app);

    expect($container->environment()['FRANKENPHP_CONFIG'])->toBe("max_threads auto\nmax_idle_time 1h\nworker {\n\tfile /app/web/frankenphp-worker.php\n}");
});

it('exposes the worker directive and MAX_REQUESTS env on the rendered docker run command so FrankenPHP and the Laravel worker actually consume them', function (): void {
    $app = makePhpApp([
        'worker_enabled' => true,
        'worker_config' => ['workers' => 4, 'max_requests' => 500],
    ]);
    $container = rendererForTest()->render($app);

    $command = (new DockerCommandBuilder)->runDetached($container);

    expect($command)->toContain("FRANKENPHP_CONFIG=max_threads auto\nmax_idle_time 1h\nworker {\n\tfile /app/public/frankenphp-worker.php\n\tnum 4\n}")
        ->and($command)->toContain("--env 'MAX_REQUESTS=500'");
});

it('changes the spec hash when worker mode toggles on the same app so the manager recreates the container', function (): void {
    $renderer = rendererForTest();
    $app = makePhpApp(['name' => 'toggle-app']);

    // Classic mode (worker disabled is the factory default).
    $classic = $renderer->render($app);

    // Flip only the worker toggle on the same app identity — name, node,
    // path, php_version, document_root all stay identical so the spec hash
    // differs strictly because of the worker fields.
    $app->worker_enabled = true;
    $app->worker_config = [
        'workers' => 'auto',
        'max_requests' => 500,
    ];

    $worker = $renderer->render($app);

    expect($classic->name())->toBe($worker->name())
        ->and($classic->appSlug())->toBe($worker->appSlug())
        ->and($classic->image())->toBe($worker->image())
        ->and($classic->specHash())->not->toBe($worker->specHash());
});

it('uses worker config defaults when worker_enabled is true and worker_config is empty', function (): void {
    $app = makePhpApp([
        'worker_enabled' => true,
        'worker_config' => null,
    ]);

    $container = rendererForTest()->render($app);

    expect($container->environment())->toMatchArray([
        'FRANKENPHP_CONFIG' => "max_threads auto\nmax_idle_time 1h\nworker {\n\tfile /app/public/frankenphp-worker.php\n}",
        'MAX_REQUESTS' => '500',
    ]);
});
