<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\DeploymentRun;
use App\Models\DeployStep;
use App\Models\Node;
use App\Services\Deploy\DeployManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

final class DeployManagerRecordingShell implements RemoteShell
{
    public array $runs = [];

    public array $results = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = compact('node', 'script', 'options');

        if ($this->results !== []) {
            return array_shift($this->results);
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 25,
        );
    }
}

function createDeployManagerTestApp(array $overrides = []): App
{
    $node = Node::factory()->appProd()->create([
        'name' => 'app-prod-1',
    ]);

    return App::factory()->create(array_merge([
        'name' => 'docs',
        'node_id' => $node->id,
        'environment' => 'production',
        'path' => '/srv/docs',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ], $overrides));
}

function createDeployManagerTestStep(App $app, string $command, string $title = 'Test step'): DeployStep
{
    return DeployStep::query()->create([
        'app_id' => $app->id,
        'title' => $title,
        'command' => $command,
        'sort_order' => 1,
        'timeout_seconds' => 120,
    ]);
}

it('routes php commands through the host php toolchain for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan migrate --force');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // user step + composer install warmup + php artisan optimize warmup
    expect($shell->runs)->toHaveCount(3)
        ->and($shell->runs[0]['script'])->toContain("'sudo'")
        ->and($shell->runs[0]['script'])->toContain("'bash'")
        ->and($shell->runs[0]['script'])->toContain("'-lc'")
        ->and($shell->runs[0]['script'])->toContain('/opt/orbit/php/')
        ->and($shell->runs[0]['script'])->toContain('php artisan migrate --force');
});

it('runs routed php deploy commands as the path-derived production app user', function (): void {
    $app = createDeployManagerTestApp([
        'path' => '/home/docs/app',
    ]);
    createDeployManagerTestStep($app, 'php artisan migrate --force');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])->toContain("'sudo' '-u' 'docs'")
        ->and($shell->runs[0]['script'])->not->toContain("'sudo' '-u' 'orbit'")
        ->and($shell->runs[0]['script'])->toContain('/home/docs/app');
});

it('routes composer commands through the host php toolchain for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'composer install --no-interaction');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])->toContain("'sudo'")
        ->and($shell->runs[0]['script'])->toContain('/opt/orbit/php/')
        ->and($shell->runs[0]['script'])->toContain('composer install --no-interaction');
});

it('routes artisan commands through the host php toolchain for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan optimize');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])->toContain("'sudo'")
        ->and($shell->runs[0]['script'])->toContain('/opt/orbit/php/')
        ->and($shell->runs[0]['script'])->toContain('php artisan optimize');
});

it('runs non-php commands on the host for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])->not->toContain('docker exec')
        ->and($shell->runs[0]['script'])->toBe('git pull origin main')
        ->and($shell->runs[0]['options']['cwd'])->toBe('/srv/docs');
});

it('runs all commands on the host for static apps', function (): void {
    $app = createDeployManagerTestApp(['runtime_kind' => AppRuntimeKind::Static]);
    createDeployManagerTestStep($app, 'php artisan migrate --force');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])->not->toContain('docker exec')
        ->and($shell->runs[0]['script'])->toBe('php artisan migrate --force');
});

it('does not transform host paths to container paths when routing through host', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'cd "{{ app_path }}" && php artisan migrate');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    $script = $shell->runs[0]['script'];
    expect($script)->toContain("'sudo'")
        ->and($script)->toContain("'-lc'")
        ->and($script)->not->toContain("'/app'");
});

it('passes deploy environment variables to the host command', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan migrate');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    $script = $shell->runs[0]['script'];
    expect($script)
        ->toContain('ORBIT_DEPLOY_APP_NAME=')
        ->toContain('docs')
        ->not->toContain("'ORBIT_DEPLOY_APP_NAME=docs'");
});

it('sets the working directory to the app source path for host commands', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'php artisan migrate');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])->toContain("'sudo'")
        ->and($shell->runs[0]['script'])->toContain('/srv/docs');
});

it('does not route php-fpm systemctl commands through host php toolchain', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'sudo systemctl reload php8.5-fpm');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs[0]['script'])->not->toContain('docker exec')
        ->and($shell->runs[0]['script'])->toBe('sudo systemctl reload php8.5-fpm');
});

it('runs built-in warmup steps on the host after user steps for php apps', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // user step (git) + composer install warmup + php artisan optimize warmup
    expect($shell->runs)->toHaveCount(3)
        ->and($shell->runs[1]['script'])->toContain('composer install --no-dev --optimize-autoloader')
        ->and($shell->runs[2]['script'])->toContain('php artisan optimize')
        ->and($shell->runs[1]['script'])->toContain("'sudo'")
        ->and($shell->runs[2]['script'])->toContain("'sudo'");
});

it('skips warmup steps when a user step fails', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    $shell->results = [
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'fail', durationMs: 25),
    ];
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);

    try {
        $manager->run('docs');
    } catch (GatewayApiException) {
        // Expected
    }

    expect($shell->runs)->toHaveCount(1)
        ->and($shell->runs[0]['script'])->toBe('git pull origin main');
});

it('does not run warmup steps for static apps', function (): void {
    $app = createDeployManagerTestApp(['runtime_kind' => AppRuntimeKind::Static]);
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    expect($shell->runs)->toHaveCount(1)
        ->and($shell->runs[0]['script'])->toBe('git pull origin main');
});

it('runs http warmup when deploy_warmup_paths is configured', function (): void {
    $app = createDeployManagerTestApp(['deploy_warmup_paths' => ['/api/health', '/']]);
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // user step + composer optimize + artisan optimize + 2 HTTP warmups
    expect($shell->runs)->toHaveCount(5)
        ->and($shell->runs[3]['script'])->toContain('curl')
        ->and($shell->runs[3]['script'])->toContain('/api/health')
        ->and($shell->runs[4]['script'])->toContain('curl')
        ->and($shell->runs[4]['script'])->toContain('/');
});

it('skips http warmup when deploy_warmup_paths is empty', function (): void {
    $app = createDeployManagerTestApp(['deploy_warmup_paths' => []]);
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;

    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    // user step + composer optimize + artisan optimize, no HTTP warmups
    expect($shell->runs)->toHaveCount(3);
});

it('uses version-matched php path in host commands', function (): void {
    $app = createDeployManagerTestApp(['php_version' => '8.4']);
    createDeployManagerTestStep($app, 'php artisan migrate');

    $shell = new DeployManagerRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);
    $manager->run('docs');

    $script = $shell->runs[0]['script'];

    expect($script)->toContain('/opt/orbit/php/')
        ->and($script)->toContain("'8.4'");
});

it('marks run failed when built-in warmup step fails', function (): void {
    $app = createDeployManagerTestApp();
    createDeployManagerTestStep($app, 'git pull origin main');

    $shell = new DeployManagerRecordingShell;
    $shell->results = [
        // User step succeeds
        new RemoteShellResult(exitCode: 0, stdout: "ok\n", stderr: '', durationMs: 25),
        // composer install succeeds
        new RemoteShellResult(exitCode: 0, stdout: "composer ok\n", stderr: '', durationMs: 25),
        // php artisan optimize fails
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'optimize failed', durationMs: 25),
    ];
    app()->instance(RemoteShell::class, $shell);

    $manager = app(DeployManager::class);

    try {
        $manager->run('docs');
        $this->fail('Expected GatewayApiException');
    } catch (GatewayApiException $e) {
        expect($e->errorCode())->toBe('deploy.warmup_failed')
            ->and($e->errorMeta()['warmup_command'])->toBe('php artisan optimize');
    }

    $run = DeploymentRun::query()->sole();
    expect($run->status)->toBe('failed')
        ->and($run->exit_code)->toBe(1);
});
