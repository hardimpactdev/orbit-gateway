<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppWorkerReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function makeReadinessApp(array $overrides = []): App
{
    $node = Node::factory()->create(['user' => 'orbit']);

    return App::factory()->for($node, 'node')->create(array_merge([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ], $overrides));
}

function readinessShell(string $stdout): RemoteShell
{
    return new class($stdout) implements RemoteShell
    {
        public function __construct(public string $stdout) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: $this->stdout,
                stderr: '',
                durationMs: 1,
            );
        }
    };
}

function runReadinessProbeAgainstFixture(string $fixtureDir, string $documentRoot = 'public'): string
{
    $workerFileRelative = $documentRoot === '' || $documentRoot === '.'
        ? 'frankenphp-worker.php'
        : trim($documentRoot, '/').'/frankenphp-worker.php';

    $probe = new AppWorkerReadiness(readinessShell(''));
    $script = $probe->probeScript($fixtureDir, $workerFileRelative);

    $tmpScript = tempnam(sys_get_temp_dir(), 'orbit-probe-').'.sh';
    file_put_contents($tmpScript, "#!/usr/bin/env bash\n".$script);
    chmod($tmpScript, 0o755);

    try {
        $output = shell_exec("bash {$tmpScript} 2>&1") ?? '';
    } finally {
        @unlink($tmpScript);
    }

    return $output;
}

function buildOctaneFixture(string $dir, array $files): void
{
    if (! is_dir($dir)) {
        mkdir($dir, recursive: true);
    }

    foreach ($files as $relative => $contents) {
        $path = $dir.'/'.$relative;
        $parent = dirname($path);
        if (! is_dir($parent)) {
            mkdir($parent, recursive: true);
        }
        file_put_contents($path, $contents);
    }
}

function readinessFixtureRoot(): string
{
    $root = sys_get_temp_dir().'/orbit-worker-readiness-'.bin2hex(random_bytes(8));
    mkdir($root, recursive: true);

    return $root;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/orbit-worker-readiness-*') ?: [] as $dir) {
        shell_exec('rm -rf '.escapeshellarg($dir));
    }
});

describe('AppWorkerReadiness service', function (): void {
    it('refuses worker mode for static apps', function (): void {
        $app = makeReadinessApp(['runtime_kind' => AppRuntimeKind::Static]);

        $result = (new AppWorkerReadiness(readinessShell('')))->assess($app);

        expect($result->ready)->toBeFalse()
            ->and($result->code)->toBe('app.worker_unsupported_runtime')
            ->and($result->missing)->toBe(['runtime_kind=php']);
    });

    it('returns app.worker_unknown_node when the app has no owning node relation', function (): void {
        // Build an App instance without saving so the node relation resolves to null.
        $app = new App;
        $app->name = 'docs';
        $app->path = '/home/orbit/apps/docs';
        $app->document_root = 'public';
        $app->php_version = '8.5';
        $app->runtime_kind = AppRuntimeKind::Php;

        $result = (new AppWorkerReadiness(readinessShell('')))->assess($app);

        expect($result->ready)->toBeFalse()
            ->and($result->code)->toBe('app.worker_unknown_node')
            ->and($result->missing)->toBe(['owning_node'])
            ->and($result->message)->toContain("App 'docs' has no owning node");
    });

    it('returns app.worker_missing_path when the app has an empty source path', function (): void {
        $app = makeReadinessApp(['path' => '']);

        $result = (new AppWorkerReadiness(readinessShell('')))->assess($app);

        expect($result->ready)->toBeFalse()
            ->and($result->code)->toBe('app.worker_missing_path')
            ->and($result->missing)->toBe(['app_path']);
    });

    it('reports missing vendor/laravel/octane when the probe omits the installed token', function (): void {
        $app = makeReadinessApp();
        $stdout = "frankenphp-worker-file:present\nfrankenphp:configured\n";

        $result = (new AppWorkerReadiness(readinessShell($stdout)))->assess($app);

        expect($result->ready)->toBeFalse()
            ->and($result->missing)->toContain('vendor/laravel/octane');
    });

    it('reports the document-root-relative worker file path when the probe omits the worker-file token', function (): void {
        $app = makeReadinessApp();
        $stdout = "octane:installed\nfrankenphp:configured\n";

        $result = (new AppWorkerReadiness(readinessShell($stdout)))->assess($app);

        expect($result->ready)->toBeFalse()
            ->and($result->missing)->toContain('public/frankenphp-worker.php');
    });

    it('reports the configured document_root in the missing worker file path, not always public/', function (): void {
        $app = makeReadinessApp(['document_root' => 'web']);
        $stdout = "octane:installed\nfrankenphp:configured\n";

        $result = (new AppWorkerReadiness(readinessShell($stdout)))->assess($app);

        expect($result->ready)->toBeFalse()
            ->and($result->missing)->toContain('web/frankenphp-worker.php')
            ->and($result->missing)->not->toContain('public/frankenphp-worker.php')
            ->and($result->meta['worker_file'] ?? null)->toBe('web/frankenphp-worker.php');
    });

    it('reports the app-root-relative worker file when document_root is empty or "."', function (): void {
        $app = makeReadinessApp(['document_root' => '.']);
        $stdout = "octane:installed\nfrankenphp:configured\n";

        $result = (new AppWorkerReadiness(readinessShell($stdout)))->assess($app);

        expect($result->ready)->toBeFalse()
            ->and($result->missing)->toContain('frankenphp-worker.php');
    });

    it('reports missing octane.server=frankenphp when the probe omits the configured token', function (): void {
        $app = makeReadinessApp();
        $stdout = "octane:installed\nfrankenphp-worker-file:present\n";

        $result = (new AppWorkerReadiness(readinessShell($stdout)))->assess($app);

        expect($result->ready)->toBeFalse()
            ->and($result->missing)->toContain('octane.server=frankenphp');
    });

    it('passes only when every required token is present', function (): void {
        $app = makeReadinessApp();
        $stdout = "octane:installed\nfrankenphp-worker-file:present\nfrankenphp:configured\n";

        $result = (new AppWorkerReadiness(readinessShell($stdout)))->assess($app);

        expect($result->ready)->toBeTrue();
    });
});

describe('AppWorkerReadiness probe script (executed against fixture filesystem)', function (): void {
    it('does not emit any tokens for a bare composer.json that only declares laravel/octane', function (): void {
        $fixture = readinessFixtureRoot();
        buildOctaneFixture($fixture, [
            'composer.json' => json_encode([
                'require' => ['laravel/octane' => '^2.0'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $output = runReadinessProbeAgainstFixture($fixture);

        expect($output)->not->toContain('octane:installed')
            ->and($output)->not->toContain('frankenphp-worker-file:present')
            ->and($output)->not->toContain('frankenphp:configured');
    });

    it('does not emit octane:installed when vendor/laravel/octane is missing', function (): void {
        $fixture = readinessFixtureRoot();
        buildOctaneFixture($fixture, [
            'composer.json' => json_encode(['require' => ['laravel/octane' => '^2.0']], JSON_THROW_ON_ERROR),
            'composer.lock' => json_encode(['packages' => [['name' => 'laravel/octane']]], JSON_THROW_ON_ERROR),
            // vendor/ intentionally absent
        ]);

        $output = runReadinessProbeAgainstFixture($fixture);

        expect($output)->not->toContain('octane:installed');
    });

    it('does not emit frankenphp:configured when octane.php only contains commented frankenphp references', function (): void {
        $fixture = readinessFixtureRoot();
        buildOctaneFixture($fixture, [
            'vendor/laravel/octane/composer.json' => '{}',
            'public/frankenphp-worker.php' => '<?php',
            'config/octane.php' => <<<'PHP'
<?php

return [
    // Default server: 'frankenphp' is what Laravel ships with, but our app
    // overrides it below. The example below is commented out.
    # 'server' => 'frankenphp',
    /* 'server' => 'frankenphp', */
    'server' => env('OCTANE_SERVER', 'swoole'),
];
PHP,
        ]);

        $output = runReadinessProbeAgainstFixture($fixture);

        expect($output)->toContain('octane:installed')
            ->and($output)->toContain('frankenphp-worker-file:present')
            ->and($output)->not->toContain('frankenphp:configured');
    });

    it('does not emit frankenphp:configured when only multi-line block comments mention frankenphp', function (): void {
        $fixture = readinessFixtureRoot();
        buildOctaneFixture($fixture, [
            'vendor/laravel/octane/composer.json' => '{}',
            'public/frankenphp-worker.php' => '<?php',
            'config/octane.php' => <<<'PHP'
<?php

/*
 * We tried 'frankenphp' but rolled back to swoole;
 * keeping the note here for historical context.
 */

return [
    'server' => env('OCTANE_SERVER', 'swoole'),
];
PHP,
        ]);

        $output = runReadinessProbeAgainstFixture($fixture);

        expect($output)->not->toContain('frankenphp:configured');
    });

    it('emits every required token when octane is fully installed and configured for frankenphp', function (): void {
        $fixture = readinessFixtureRoot();
        buildOctaneFixture($fixture, [
            'vendor/laravel/octane/composer.json' => '{}',
            'public/frankenphp-worker.php' => '<?php',
            'config/octane.php' => <<<'PHP'
<?php

return [
    'server' => env('OCTANE_SERVER', 'frankenphp'),
];
PHP,
        ]);

        $output = runReadinessProbeAgainstFixture($fixture);

        expect($output)->toContain('octane:installed')
            ->and($output)->toContain('frankenphp-worker-file:present')
            ->and($output)->toContain('frankenphp:configured');
    });

    it('still emits frankenphp:configured when frankenphp appears on a line with a trailing comment', function (): void {
        $fixture = readinessFixtureRoot();
        buildOctaneFixture($fixture, [
            'vendor/laravel/octane/composer.json' => '{}',
            'public/frankenphp-worker.php' => '<?php',
            'config/octane.php' => <<<'PHP'
<?php

return [
    'server' => 'frankenphp', // primary octane server for production
];
PHP,
        ]);

        $output = runReadinessProbeAgainstFixture($fixture);

        expect($output)->toContain('frankenphp:configured');
    });

    it('does not emit any tokens when the app path is empty', function (): void {
        $fixture = readinessFixtureRoot();
        // No files at all under fixture root.

        $output = runReadinessProbeAgainstFixture($fixture);

        expect($output)->not->toContain('octane:installed')
            ->and($output)->not->toContain('frankenphp-worker-file:present')
            ->and($output)->not->toContain('frankenphp:configured');
    });

    it('does not emit frankenphp-worker-file:present when document_root=web but only public/frankenphp-worker.php exists', function (): void {
        $fixture = readinessFixtureRoot();
        buildOctaneFixture($fixture, [
            'vendor/laravel/octane/composer.json' => '{}',
            // Worker file is in public/ but the app serves from web/. This is
            // the exact false-pass the readiness reviewer flagged.
            'public/frankenphp-worker.php' => '<?php',
            'config/octane.php' => "<?php\nreturn ['server' => 'frankenphp'];\n",
        ]);

        $output = runReadinessProbeAgainstFixture($fixture, documentRoot: 'web');

        expect($output)->toContain('octane:installed')
            ->and($output)->not->toContain('frankenphp-worker-file:present')
            ->and($output)->toContain('frankenphp:configured');
    });

    it('emits frankenphp-worker-file:present when document_root=web and web/frankenphp-worker.php exists', function (): void {
        $fixture = readinessFixtureRoot();
        buildOctaneFixture($fixture, [
            'vendor/laravel/octane/composer.json' => '{}',
            'web/frankenphp-worker.php' => '<?php',
            'config/octane.php' => "<?php\nreturn ['server' => 'frankenphp'];\n",
        ]);

        $output = runReadinessProbeAgainstFixture($fixture, documentRoot: 'web');

        expect($output)->toContain('octane:installed')
            ->and($output)->toContain('frankenphp-worker-file:present')
            ->and($output)->toContain('frankenphp:configured');
    });

    it('emits frankenphp-worker-file:present when document_root=. and frankenphp-worker.php is at the app root', function (): void {
        $fixture = readinessFixtureRoot();
        buildOctaneFixture($fixture, [
            'vendor/laravel/octane/composer.json' => '{}',
            'frankenphp-worker.php' => '<?php',
            'config/octane.php' => "<?php\nreturn ['server' => 'frankenphp'];\n",
        ]);

        $output = runReadinessProbeAgainstFixture($fixture, documentRoot: '.');

        expect($output)->toContain('frankenphp-worker-file:present');
    });
});
