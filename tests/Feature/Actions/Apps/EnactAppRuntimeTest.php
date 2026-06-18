<?php

declare(strict_types=1);

use App\Actions\Apps\EnactAppRuntime;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process as OrbitProcess;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

function makeAppOnDevNode(AppRuntimeKind $kind = AppRuntimeKind::Php): App
{
    $node = Node::factory()->create([
        'status' => 'active',
        'user' => 'orbit',
        'tld' => 'test',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    return App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'php_version' => '8.5',
        'runtime_kind' => $kind,
    ]);
}

function makeAppOnProdNode(AppRuntimeKind $kind = AppRuntimeKind::Php): App
{
    $ingress = Node::factory()->ingress()->create([
        'wireguard_address' => '10.6.0.10',
    ]);
    Node::factory()->router()->create([
        'wireguard_address' => '10.6.0.2',
    ]);

    $node = Node::factory()->create([
        'status' => 'active',
        'user' => 'orbit',
        'wireguard_address' => '10.6.0.4',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-prod',
        'status' => 'active',
        'settings' => [
            'ingress_node_id' => $ingress->id,
        ],
    ]);

    return App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'environment' => 'production',
        'path' => '/home/docs/app',
        'php_version' => '8.5',
        'runtime_kind' => $kind,
    ]);
}

final class EnactAppRuntimeRecordingShell implements RemoteShell
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

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

it('converges a FrankenPHP runtime container for PHP apps and writes the php.ini config', function (): void {
    $app = makeAppOnDevNode(AppRuntimeKind::Php);

    $shell = new EnactAppRuntimeRecordingShell(
        // network inspect (missing) + network create
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: absent
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        // image inspect succeeds (image present on node) — preflight runs
        // after container inspect so the apply path knows hadExistingContainer
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        // create script
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );
    app()->instance(RemoteShell::class, $shell);

    $drift = app(EnactAppRuntime::class)->handle($app);

    $runtimeScripts = array_slice($shell->scripts, 0, 5);

    expect($drift)->toBe([])
        ->and($runtimeScripts[0])->toContain('docker network inspect')
        ->and($runtimeScripts[2])->toContain('docker container inspect')
        ->and($runtimeScripts[3])->toContain("docker image inspect 'dunglas/frankenphp:1-php8.5-bookworm'")
        ->and($runtimeScripts[4])->toContain('docker run -d')
        ->and($runtimeScripts[4])->toContain("'orbit-app-docs'")
        ->and($runtimeScripts[4])->toContain("'dunglas/frankenphp:1-php8.5-bookworm'")
        ->and($runtimeScripts[4])->toContain("'/etc/orbit/apps/docs.ini'")
        ->and(base64DecodedPhpIni($runtimeScripts[4]))->toContain('opcache.enable=1')
        ->and(base64DecodedPhpIni($runtimeScripts[4]))->toContain('realpath_cache_size=4096K');

    expectAppFrankenPhpRuntimeProcess($app);
});

function base64DecodedPhpIni(string $script): string
{
    if (preg_match("/printf %s\\s+'([A-Za-z0-9+\\/=]+)'/", $script, $match) !== 1) {
        return '';
    }

    return (string) base64_decode($match[1], true);
}

it('skips the FrankenPHP runtime container for static apps and serves the proxy route via file_server only', function (): void {
    $app = makeAppOnDevNode(AppRuntimeKind::Static);

    $shell = new EnactAppRuntimeRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    $drift = app(EnactAppRuntime::class)->handle($app);

    expect($drift)->toBe([]);

    foreach ($shell->scripts as $script) {
        expect($script)->not->toContain('docker run -d')
            ->and($script)->not->toContain('docker network inspect')
            ->and($script)->not->toContain('docker container inspect');
    }

    $route = ProxyRoute::query()->where('app_id', $app->id)->firstOrFail();

    expect($route->config)->toMatchArray([
        'document_root' => '/home/orbit/apps/docs/public',
        'php_socket' => null,
    ]);

    $siteScript = collect($shell->scripts)
        ->first(fn (string $script): bool => str_contains($script, '/etc/caddy/sites/'));

    $caddySite = base64_decode((string) str((string) $siteScript)->match("/printf %s\\s+'([A-Za-z0-9+\\/=]+)'/")->toString(), true);

    expect($caddySite)->toContain('file_server')
        ->and($caddySite)->not->toContain('php_fastcgi');
});

it('returns app.runtime_container_missing when installing the container fails', function (): void {
    $app = makeAppOnDevNode(AppRuntimeKind::Php);

    $shell = new EnactAppRuntimeRecordingShell(
        // network inspect (missing) + network create
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: absent
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        // image inspect: present (so we don't take the php_version_unavailable path)
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        // docker run fails for some other reason (mount, network, etc.)
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'mount denied', durationMs: 1),
    );
    app()->instance(RemoteShell::class, $shell);

    $drift = app(EnactAppRuntime::class)->handle($app);

    // The runtime warning is preserved AND EnsureAppProxyRoute still runs so
    // gateway-side app-owned proxy route configuration is recorded. Without
    // this, the warning's `doctor --family=app --restore` next_command
    // would have no proxy route to converge against (app doctor does not
    // edit app-owned proxy routes).
    expect(collect($drift)->firstWhere('code', 'app.runtime_container_missing'))->not->toBeNull()
        ->and(collect($drift)->firstWhere('code', 'app.runtime_container_missing'))->toMatchArray([
            'family' => 'app',
            'next_command' => 'doctor --family=app --restore',
        ])
        ->and(ProxyRoute::query()->where('app_id', $app->id)->exists())->toBeTrue();
});

it('returns app.security.system_user when production runtime user resolution fails before creating the container', function (): void {
    $app = makeAppOnProdNode(AppRuntimeKind::Php);

    $shell = new EnactAppRuntimeRecordingShell(
        // network inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: absent
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        // image inspect: present
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        // runtime user lookup fails
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'id: docs: no such user', durationMs: 1),
    );
    app()->instance(RemoteShell::class, $shell);

    $drift = app(EnactAppRuntime::class)->handle($app);

    $systemUser = collect($drift)->firstWhere('code', 'app.security.system_user');

    expect($systemUser)->not->toBeNull()
        ->and($systemUser['family'])->toBe('app')
        ->and($systemUser['next_command'])->toBe('doctor --family=app --restore')
        ->and($systemUser['message'])->toContain("Production runtime user 'docs'")
        ->and(collect($drift)->firstWhere('code', 'app.runtime_container_missing'))->toBeNull()
        ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, 'docker run -d')))->toBeFalse()
        ->and(ProxyRoute::query()->where('app_id', $app->id)->exists())->toBeTrue();
});

it('reconciles an existing FrankenPHP app runtime process row', function (): void {
    $app = makeAppOnDevNode(AppRuntimeKind::Php);

    OrbitProcess::factory()->forOwner($app)->create([
        'name' => 'frankenphp-docs',
        'command' => 'stale command',
        'restart_policy' => ProcessRestartPolicy::Never,
        'crash_notification' => ProcessCrashNotification::AgentIde,
        'runtime' => ProcessRuntime::Systemd,
        'runtime_config' => [
            'container_name' => 'stale-container',
            'php_ini_path' => '/stale.ini',
        ],
    ]);

    $shell = new EnactAppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    );
    app()->instance(RemoteShell::class, $shell);

    app(EnactAppRuntime::class)->handle($app);

    expectAppFrankenPhpRuntimeProcess($app);
});

it('returns app.php_version_unavailable when the selected FrankenPHP image is missing on the owning node', function (): void {
    $app = makeAppOnDevNode(AppRuntimeKind::Php);

    $shell = new EnactAppRuntimeRecordingShell(
        // network inspect (missing) + network create
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: absent
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        // image inspect: definite "No such image" — image not on node
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Error: No such image: dunglas/frankenphp:1-php8.5-bookworm', durationMs: 1),
    );
    app()->instance(RemoteShell::class, $shell);

    $drift = app(EnactAppRuntime::class)->handle($app);

    $phpUnavailable = collect($drift)->firstWhere('code', 'app.php_version_unavailable');

    // The image-unavailable warning is preserved, but EnsureAppProxyRoute
    // still runs so the route row exists and app doctor's
    // `doctor --family=app --restore` has something to converge
    // against once the image is made available on the node.
    expect($phpUnavailable)->not->toBeNull()
        ->and($phpUnavailable['family'])->toBe('app')
        ->and($phpUnavailable['next_command'])->toBe('doctor --family=app --restore')
        ->and($phpUnavailable['message'])->toContain('dunglas/frankenphp:1-php8.5-bookworm')
        ->and(ProxyRoute::query()->where('app_id', $app->id)->exists())->toBeTrue();
});

it('returns app.runtime_container_missing (NOT app.php_version_unavailable) when the docker image probe fails for an unknown reason and no container existed', function (): void {
    $app = makeAppOnDevNode(AppRuntimeKind::Php);

    $shell = new EnactAppRuntimeRecordingShell(
        // network inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: absent
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
        // image inspect: unknown Docker error — must surface as
        // runtime-container drift, NEVER as app.php_version_unavailable
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock.', durationMs: 1),
    );
    app()->instance(RemoteShell::class, $shell);

    $drift = app(EnactAppRuntime::class)->handle($app);

    $missing = collect($drift)->firstWhere('code', 'app.runtime_container_missing');

    expect($missing)->not->toBeNull()
        ->and(collect($drift)->firstWhere('code', 'app.php_version_unavailable'))->toBeNull()
        ->and($missing['next_command'])->toBe('doctor --family=app --restore')
        ->and(ProxyRoute::query()->where('app_id', $app->id)->exists())->toBeTrue();
});

it('returns app.runtime_container_mismatch (NOT app.php_version_unavailable) when the docker image probe fails for an unknown reason but a container already existed', function (): void {
    $app = makeAppOnDevNode(AppRuntimeKind::Php);

    $inspectPayload = json_encode([
        'State' => ['Running' => true],
        'Config' => ['Labels' => ['orbit.app.spec_hash' => 'whatever']],
    ], JSON_THROW_ON_ERROR);

    $shell = new EnactAppRuntimeRecordingShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: matching container exists
        new RemoteShellResult(exitCode: 0, stdout: $inspectPayload, stderr: '', durationMs: 1),
        // image inspect: unknown failure (daemon flake / permission)
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
    );
    app()->instance(RemoteShell::class, $shell);

    $drift = app(EnactAppRuntime::class)->handle($app);

    $mismatch = collect($drift)->firstWhere('code', 'app.runtime_container_mismatch');

    expect($mismatch)->not->toBeNull()
        ->and(collect($drift)->firstWhere('code', 'app.php_version_unavailable'))->toBeNull()
        ->and(ProxyRoute::query()->where('app_id', $app->id)->exists())->toBeTrue();
});

it('returns app.php_version_unavailable when the image is pruned out from under a matching container (not runtime_container_*)', function (): void {
    $app = makeAppOnDevNode(AppRuntimeKind::Php);

    $inspectPayload = json_encode([
        'State' => ['Running' => true],
        'Config' => ['Labels' => ['orbit.app.spec_hash' => 'any']],
    ], JSON_THROW_ON_ERROR);

    $shell = new EnactAppRuntimeRecordingShell(
        // network inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: matching running container
        new RemoteShellResult(exitCode: 0, stdout: $inspectPayload, stderr: '', durationMs: 1),
        // image inspect: "No such image" — image pruned out from under an
        // otherwise existing container. Must surface as php_version_unavailable
        // rather than collapsing into runtime_container_missing/mismatch.
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'Error: No such image: dunglas/frankenphp:1-php8.5-bookworm', durationMs: 1),
    );
    app()->instance(RemoteShell::class, $shell);

    $drift = app(EnactAppRuntime::class)->handle($app);

    $phpUnavailable = collect($drift)->firstWhere('code', 'app.php_version_unavailable');

    expect($phpUnavailable)->not->toBeNull()
        ->and(collect($drift)->firstWhere('code', 'app.runtime_container_missing'))->toBeNull()
        ->and(collect($drift)->firstWhere('code', 'app.runtime_container_mismatch'))->toBeNull()
        ->and(ProxyRoute::query()->where('app_id', $app->id)->exists())->toBeTrue();
});

it('returns app.runtime_container_mismatch when recreating a drifted container fails', function (): void {
    $app = makeAppOnDevNode(AppRuntimeKind::Php);

    $inspectPayload = json_encode([
        'State' => ['Running' => true],
        'Config' => ['Labels' => ['orbit.app.spec_hash' => 'stale-hash-from-a-previous-spec']],
    ], JSON_THROW_ON_ERROR);

    $shell = new EnactAppRuntimeRecordingShell(
        // network inspect ok
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        // container inspect: drift
        new RemoteShellResult(exitCode: 0, stdout: $inspectPayload, stderr: '', durationMs: 1),
        // image inspect: present
        new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        // rm -f fails
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'container in use by another process', durationMs: 1),
    );
    app()->instance(RemoteShell::class, $shell);

    $drift = app(EnactAppRuntime::class)->handle($app);

    $mismatch = collect($drift)->firstWhere('code', 'app.runtime_container_mismatch');

    expect($mismatch)->not->toBeNull()
        ->and($mismatch['family'])->toBe('app')
        ->and($mismatch['next_command'])->toBe('doctor --family=app --restore')
        // Route row exists so app doctor's restore has something to converge.
        ->and(ProxyRoute::query()->where('app_id', $app->id)->exists())->toBeTrue();
});

it('throws when the app has no owning node', function (): void {
    $app = App::factory()->make([
        'name' => 'orphan',
        'path' => '/home/orbit/apps/orphan',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
        'node_id' => 99999,
    ]);
    $app->setRelation('node', null);

    expect(fn () => app(EnactAppRuntime::class)->handle($app))->toThrow(RuntimeException::class);
});

function expectAppFrankenPhpRuntimeProcess(App $app): void
{
    $process = OrbitProcess::query()
        ->ownedBy($app)
        ->where('name', "frankenphp-{$app->name}")
        ->first();

    expect($process)->not->toBeNull()
        ->and($process?->node_id)->toBe($app->node_id)
        ->and($process?->command)->toBe('frankenphp')
        ->and($process?->restart_policy)->toBe(ProcessRestartPolicy::Always)
        ->and($process?->crash_notification)->toBe(ProcessCrashNotification::None)
        ->and($process?->runtime)->toBe(ProcessRuntime::Docker)
        ->and($process?->tool)->toBeNull()
        ->and($process?->runtime_config)->toMatchArray([
            'container_name' => 'orbit-app-docs',
            'php_ini_path' => '/etc/orbit/apps/docs.ini',
            'container_spec_hash_label' => 'orbit.app.spec_hash',
        ]);
}
