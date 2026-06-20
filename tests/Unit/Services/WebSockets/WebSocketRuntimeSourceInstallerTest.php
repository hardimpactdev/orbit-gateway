<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\WebSockets\WebSocketRoleBaselineTiming;
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\WebSockets\WebSocketRuntimeSourceInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('installs the WebSocket Reverb runtime source through a Docker-first script', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $shell = new WebSocketRuntimeSourceInstallerTestShell;

    (new WebSocketRuntimeSourceInstaller($shell))->install($node);

    $script = $shell->scripts[0];
    $timingSteps = array_column(app(WebSocketRoleBaselineTiming::class)->records(), 'step');

    expect($shell->nodes[0]->is($node))->toBeTrue()
        ->and($shell->options[0])->toMatchArray([
            'throw' => true,
            'metadata' => [
                'ORBIT_OPERATION_ID' => 'websocket-runtime-source-install',
            ],
        ])
        ->and($script)->toContain('release_dir="${runtime_root}/releases/')
        ->and($script)->toContain('sudo install -d -m 0755 "$release_dir"')
        ->and($script)->toContain('sudo ln -sfn "releases/${expected_hash}" \''.WebSocketRuntimeContainer::SourceHostPath."'")
        ->and($script)->toContain('__orbit_websocket_source_timing')
        ->and($script)->toContain('record_timing composer')
        ->and($script)->not->toContain('orbit-gateway:current')
        ->and($script)->not->toContain('docker image inspect')
        ->and($script)->not->toContain('docker run --rm')
        ->and($script)->toContain('sudo env COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress')
        ->and($script)->toContain('WebSocket runtime dependencies require host composer.')
        ->and($script)->toContain('vendor/autoload.php')
        ->and($script)->toContain('app_key="base64:$(head -c 32 /dev/urandom | base64')
        ->and($script)->toContain("printf 'APP_KEY=%s\\n'")
        ->and($script)->toContain(WebSocketRuntimeSourceInstaller::AppsConfigPath)
        ->and($timingSteps)->toContain('source-files')
        ->and($timingSteps)->toContain('source-hash')
        ->and($timingSteps)->toContain('source-archive')
        ->and($timingSteps)->toContain('source-remote')
        ->and($timingSteps)->toContain('source-composer')
        ->and($script)->not->toContain("\nphp artisan")
        ->and($script)->not->toContain('reverb:install')
        ->and($script)->not->toContain('install:broadcasting');
});

it('ships a bootable Laravel Reverb source artifact without committed vendor files', function (): void {
    $sourcePath = repo_path('apps/reverb');

    expect("{$sourcePath}/artisan")->toBeFile()
        ->and("{$sourcePath}/bootstrap/app.php")->toBeFile()
        ->and("{$sourcePath}/composer.json")->toBeFile()
        ->and("{$sourcePath}/composer.lock")->toBeFile()
        ->and("{$sourcePath}/config/reverb.php")->toBeFile()
        ->and("{$sourcePath}/vendor")->not->toBeDirectory();

    expect(file_get_contents("{$sourcePath}/config/reverb.php"))->toContain('ORBIT_WEBSOCKET_APPS_CONFIG');

    $composer = json_decode(file_get_contents("{$sourcePath}/composer.json") ?: '', true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require'])->toMatchArray([
        'php' => '^8.5',
        'laravel/framework' => '13.7.0',
        'laravel/reverb' => '^1.10',
    ]);
});

it('defers fallback source path validation until source install runs', function (): void {
    $shell = new WebSocketRuntimeSourceInstallerTestShell;

    expect(fn () => new WebSocketRuntimeSourceInstaller($shell, sourcePath: '/missing/orbit-reverb'))
        ->not->toThrow(InvalidArgumentException::class);

    expect(fn () => (new WebSocketRuntimeSourceInstaller($shell, sourcePath: '/missing/orbit-reverb'))->install(Node::factory()->create()))
        ->toThrow(InvalidArgumentException::class, 'WebSocket runtime source path [/missing/orbit-reverb] does not exist.');
});

final class WebSocketRuntimeSourceInstallerTestShell implements RemoteShell
{
    /**
     * @var list<Node>
     */
    public array $nodes = [];

    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;
        $this->options[] = $options;

        return new RemoteShellResult(
            exitCode: 0,
            stdout: implode("\n", [
                '__orbit_websocket_source_timing setup 1',
                '__orbit_websocket_source_timing extract 2',
                '__orbit_websocket_source_timing env 3',
                '__orbit_websocket_source_timing composer 4',
                '__orbit_websocket_source_timing activate 5',
            ]),
            stderr: '',
            durationMs: 1,
        );
    }
}
