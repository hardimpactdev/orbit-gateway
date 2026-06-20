<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\AppRuntimeUser;
use App\Services\Apps\AppsFixer;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

final class AppsFixerRecordingRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var list<RemoteShellResult> */
    public array $responses;

    public function __construct(RemoteShellResult ...$responses)
    {
        $this->responses = $responses;
    }

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->responses) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

function buildAppsFixer(RemoteShell $shell): AppsFixer
{
    return new AppsFixer(
        $shell,
        new AppRuntimeContainerRenderer(new PhpRuntimePolicy(new PhpRuntimeCatalog), new OrbitContainerNames),
        new AppRuntimeContainerManager($shell, new DockerCommandBuilder),
        new AppRuntimeUser,
    );
}

function appsFixerNode(): Node
{
    return createTestAppHostNode(['name' => 'app-1', 'user' => 'orbit']);
}

it('re-applies a missing FrankenPHP runtime container via the manager', function (): void {
    $node = appsFixerNode();
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $shell = new AppsFixerRecordingRemoteShell(
        // network inspect (missing) + network create
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: absent
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        // image inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        // create script
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $result = buildAppsFixer($shell)->fix($app, new DriftEntry(
        family: 'app',
        key: 'app.runtime_container_missing',
        kind: DriftKind::Missing,
        summary: 'missing',
    ));

    expect($result)->toMatchArray([
        'family' => 'app',
        'node' => 'app-1',
        'key' => 'app.runtime_container_missing',
        'status' => 'completed',
    ])
        ->and($result['details']['container'])->toBe('orbit-app-docs')
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, 'docker run -d') && str_contains($script, "'orbit-app-docs'")))->toBeTrue();
});

it('re-applies a mismatched FrankenPHP runtime container by removing and recreating it', function (): void {
    $node = appsFixerNode();
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $inspectPayload = json_encode([
        'State' => ['Running' => true],
        'Config' => ['Labels' => ['orbit.app.spec_hash' => 'stale']],
    ], JSON_THROW_ON_ERROR);

    $shell = new AppsFixerRecordingRemoteShell(
        // network inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: drift
        new RemoteShellResult(exitCode: 0, stdout: $inspectPayload, stderr: '', durationMs: 1),
        // image inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        // docker rm
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // create script
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $result = buildAppsFixer($shell)->fix($app, new DriftEntry(
        family: 'app',
        key: 'app.runtime_container_mismatch',
        kind: DriftKind::Divergent,
        summary: 'mismatch',
    ));

    expect($result)->toMatchArray([
        'family' => 'app',
        'node' => 'app-1',
        'key' => 'app.runtime_container_mismatch',
        'status' => 'completed',
    ])
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, "docker rm -f 'orbit-app-docs'")))->toBeTrue()
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, 'docker run -d')))->toBeTrue();
});

it('returns null for non-app-runtime drift keys', function (): void {
    $node = appsFixerNode();
    $app = App::factory()->for($node, 'node')->create(['name' => 'docs']);

    $result = buildAppsFixer(new AppsFixerRecordingRemoteShell)->fix($app, new DriftEntry(
        family: 'app',
        key: 'app.path_missing',
        kind: DriftKind::Missing,
        summary: 'missing',
    ));

    expect($result)->toBeNull();
});

it('returns null for static apps even on runtime container drift keys', function (): void {
    $node = appsFixerNode();
    $app = App::factory()->for($node, 'node')->static()->create(['name' => 'marketing']);

    $shell = new AppsFixerRecordingRemoteShell;

    $result = buildAppsFixer($shell)->fix($app, new DriftEntry(
        family: 'app',
        key: 'app.runtime_container_missing',
        kind: DriftKind::Missing,
        summary: 'missing',
    ));

    expect($result)->toBeNull()
        ->and($shell->scripts)->toBe([]);
});

it('removes an orphan app runtime container at the node when handed an app slug without an active App row', function (): void {
    $node = appsFixerNode();

    $inspectPayload = json_encode(['State' => ['Running' => true], 'Config' => ['Labels' => []]], JSON_THROW_ON_ERROR);

    $shell = new AppsFixerRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: $inspectPayload, stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $result = buildAppsFixer($shell)->removeExtra($node, 'orphan-docs');

    expect($result)->toMatchArray([
        'family' => 'app',
        'node' => 'app-1',
        'code' => 'app.runtime_container_extra',
        'key' => 'app.runtime_container_extra',
        'mode' => 'fix',
        'status' => 'completed',
    ])
        ->and($result['details']['app'])->toBe('orphan-docs')
        ->and($result['details']['container'])->toBe('orbit-app-orphan-docs')
        ->and($result['details']['outcome'])->toBe('removed')
        ->and($shell->scripts[1])->toContain("docker rm -f 'orbit-app-orphan-docs'");
});

it('reports already_absent without throwing when the orphan container does not exist anymore', function (): void {
    $node = appsFixerNode();

    $shell = new AppsFixerRecordingRemoteShell(
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'no such container', durationMs: 1),
    );

    $result = buildAppsFixer($shell)->removeExtra($node, 'orphan-docs');

    expect($result['status'])->toBe('completed')
        ->and($result['details']['outcome'])->toBe('already_absent');
});

it('propagates docker failures from removeExtra so doctor can record the failure', function (): void {
    $node = appsFixerNode();

    $inspectPayload = json_encode(['State' => ['Running' => true], 'Config' => ['Labels' => []]], JSON_THROW_ON_ERROR);

    $shell = new AppsFixerRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: $inspectPayload, stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'container in use', durationMs: 1),
    );

    expect(fn () => buildAppsFixer($shell)->removeExtra($node, 'orphan-docs'))
        ->toThrow(RuntimeException::class);
});

it('removes an orphan managed runtime config file when handed an app slug without an active App row', function (): void {
    $node = appsFixerNode();

    $shell = new AppsFixerRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:present\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:absent\n", stderr: '', durationMs: 1),
    );

    $result = buildAppsFixer($shell)->removeRuntimeConfigExtra($node, 'orphan-docs');

    expect($result['status'])->toBe('completed')
        ->and($result['key'])->toBe('app.runtime_config_extra')
        ->and($result['details']['path'])->toBe('/etc/orbit/apps/orphan-docs.ini')
        ->and($result['details']['outcome'])->toBe('removed')
        ->and($shell->scripts[1])->toContain("sudo rm -f '/etc/orbit/apps/orphan-docs.ini'");
});

it('reports already_absent without throwing when the orphan runtime config is already gone', function (): void {
    $node = appsFixerNode();

    $shell = new AppsFixerRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:absent\n", stderr: '', durationMs: 1),
    );

    $result = buildAppsFixer($shell)->removeRuntimeConfigExtra($node, 'orphan-docs');

    expect($result['details']['outcome'])->toBe('already_absent');
});

it('throws when the orphan runtime config cannot be removed so doctor records the failure', function (): void {
    $node = appsFixerNode();

    $shell = new AppsFixerRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:present\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
    );

    expect(fn () => buildAppsFixer($shell)->removeRuntimeConfigExtra($node, 'orphan-docs'))
        ->toThrow(RuntimeException::class);
});

it('throws from removeRuntimeConfigExtra when the sudo probe fails for an unknown reason so doctor records the failure', function (): void {
    $node = appsFixerNode();

    $shell = new AppsFixerRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:error\n", stderr: 'sudo: no tty present', durationMs: 1),
    );

    expect(fn () => buildAppsFixer($shell)->removeRuntimeConfigExtra($node, 'orphan-docs'))
        ->toThrow(RuntimeException::class);
});

it('throws from removeExtra when the docker inspect probe fails for an unknown reason so doctor records the failure', function (): void {
    $node = appsFixerNode();

    $shell = new AppsFixerRecordingRemoteShell(
        new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock.',
            durationMs: 1,
        ),
    );

    expect(fn () => buildAppsFixer($shell)->removeExtra($node, 'orphan-docs'))
        ->toThrow(RuntimeException::class);
});

it('rewrites the managed runtime config when handed app.runtime_config_missing', function (): void {
    $node = appsFixerNode();
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $shell = new AppsFixerRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $result = buildAppsFixer($shell)->fix($app, new DriftEntry(
        family: 'app',
        key: 'app.runtime_config_missing',
        kind: DriftKind::Missing,
        summary: 'missing',
    ));

    expect($result['status'])->toBe('completed')
        ->and($result['key'])->toBe('app.runtime_config_missing')
        ->and($result['details']['path'])->toBe('/etc/orbit/apps/docs.ini')
        ->and($shell->scripts[0])->toContain('/etc/orbit/apps/docs.ini')
        ->and($shell->scripts[0])->toContain('base64 -d');
});

it('rewrites the managed runtime config when handed app.runtime_config_mismatch', function (): void {
    $node = appsFixerNode();
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $shell = new AppsFixerRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $result = buildAppsFixer($shell)->fix($app, new DriftEntry(
        family: 'app',
        key: 'app.runtime_config_mismatch',
        kind: DriftKind::Divergent,
        summary: 'mismatch',
    ));

    expect($result['status'])->toBe('completed')
        ->and($result['key'])->toBe('app.runtime_config_mismatch');
});

it('repairs the production runtime user when handed app.security.system_user', function (): void {
    $node = createTestAppHostNode(['name' => 'app-1'], 'app-prod');
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $shell = new AppsFixerRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $result = buildAppsFixer($shell)->fix($app, new DriftEntry(
        family: 'app',
        key: 'app.security.system_user',
        kind: DriftKind::Missing,
        summary: 'missing',
    ));

    expect($result['status'])->toBe('completed')
        ->and($result['key'])->toBe('app.security.system_user')
        ->and($shell->scripts[0])->toContain('useradd')
        ->and($shell->scripts[0])->toContain('--system')
        ->and($shell->scripts[0])->toContain('chown -R')
        ->and($shell->scripts[0])->toContain("'/home/orbit/apps/docs'")
        ->and($shell->scripts[0])->not->toContain('usermod')
        ->and($shell->scripts[0])->not->toContain('docker')
        ->and($shell->scripts[0])->not->toContain('/var/run/docker.sock');
});

it('reapplies filesystem ownership when handed app.security.fs_permissions', function (): void {
    $node = createTestAppHostNode(['name' => 'app-1'], 'app-prod');
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $shell = new AppsFixerRecordingRemoteShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $result = buildAppsFixer($shell)->fix($app, new DriftEntry(
        family: 'app',
        key: 'app.security.fs_permissions',
        kind: DriftKind::Divergent,
        summary: 'permissions',
    ));

    expect($result['status'])->toBe('completed')
        ->and($result['key'])->toBe('app.security.fs_permissions')
        ->and($shell->scripts[0])->toContain('chown -R')
        ->and($shell->scripts[0])->toContain('chmod -R go-w');
});

it('repairs production runtime container isolation by re-applying the container', function (): void {
    $node = appsFixerNode();
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ]);

    $shell = new AppsFixerRecordingRemoteShell(
        // network inspect (missing) + network create
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: absent
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        // image inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        // create script
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );

    $result = buildAppsFixer($shell)->fix($app, new DriftEntry(
        family: 'app',
        key: 'app.security.runtime_container_isolation',
        kind: DriftKind::Missing,
        summary: 'isolation',
    ));

    expect($result)->toMatchArray([
        'family' => 'app',
        'node' => 'app-1',
        'key' => 'app.security.runtime_container_isolation',
        'status' => 'completed',
    ]);
});
