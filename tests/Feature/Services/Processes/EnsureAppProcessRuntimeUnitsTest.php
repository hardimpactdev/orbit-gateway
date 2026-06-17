<?php

declare(strict_types=1);

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeEnsureRuntimeUnitsAction(RemoteShell $remoteShell, SiteCertificateInstaller $certificates): EnsureAppProcessRuntimeUnits
{
    app()->instance(RemoteShell::class, $remoteShell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    return app(EnsureAppProcessRuntimeUnits::class);
}

it('renders and enacts systemd units for app process definitions', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
        'runtime_kind' => AppRuntimeKind::Static,
    ]);
    $app->setRelation('node', $node);

    OrbitProcess::factory()->forOwner($app)->create([
        'name' => 'vite',
        'command' => 'npm run dev -- --host=0.0.0.0',
        'restart_policy' => 'on_failure',
        'crash_notification' => 'none',
        'runtime' => ProcessRuntime::Systemd,
        'sort_order' => 1,
    ]);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    $certificates = new ProcessRuntimeRecordingSiteCertificateInstaller;

    $warnings = makeEnsureRuntimeUnitsAction($remoteShell, $certificates)->handle($app);

    expect($warnings)->toBe([])
        ->and($remoteShell->scripts)->toHaveCount(1)
        ->and($remoteShell->scripts[0])->toContain("sudo tee '/etc/systemd/system/orbit_docs_main_vite.service' >/dev/null")
        ->and($remoteShell->scripts[0])->toContain('[Unit]')
        ->and($remoteShell->scripts[0])->toContain('Description=Orbit process orbit_docs_main_vite')
        ->and($remoteShell->scripts[0])->toContain('WorkingDirectory=/home/orbit/apps/docs')
        ->and($remoteShell->scripts[0])->toContain("ExecStart=/bin/bash -lc 'npm run dev -- --host=0.0.0.0'")
        ->and($remoteShell->scripts[0])->toContain('Restart=on-failure')
        ->and($remoteShell->scripts[0])->toContain('Environment="APP_URL=https://docs.test"')
        ->and($remoteShell->scripts[0])->toContain('Environment="VITE_VALET_HOST=docs.test"')
        ->and($remoteShell->scripts[0])->toContain('Environment="VITE_DEV_SERVER_KEY=/home/orbit/.config/orbit/certs/docs.test.key"')
        ->and($remoteShell->scripts[0])->toContain('Environment="VITE_DEV_SERVER_CERT=/home/orbit/.config/orbit/certs/docs.test.crt"')
        ->and($certificates->hosts)->toBe(['docs.test'])
        ->and($remoteShell->scripts[0])->toContain("sudo systemctl enable 'orbit_docs_main_vite.service' >/dev/null");
});

it('reports process family warnings when systemd unit enactment fails after intent exists', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'runtime_kind' => AppRuntimeKind::Static,
    ]);
    $app->setRelation('node', $node);

    OrbitProcess::factory()->forOwner($app)->create([
        'name' => 'worker',
        'command' => 'php artisan queue:work',
        'restart_policy' => 'always',
        'crash_notification' => 'none',
        'runtime' => ProcessRuntime::Systemd,
        'sort_order' => 1,
    ]);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'systemctl failed', durationMs: 1),
    ]);

    $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeRecordingSiteCertificateInstaller)->handle($app);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toMatchArray([
            'code' => 'process.runtime_unit_missing',
            'family' => 'process',
            'next_command' => 'doctor --family=process --restore',
        ])
        ->and($remoteShell->scripts)->toHaveCount(1);
});

it('reports process.tls_certificate_missing when the site certificate installer throws and still continues to the next workspace context', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'runtime_kind' => AppRuntimeKind::Static,
    ]);
    $app->setRelation('node', $node);

    OrbitProcess::factory()->forOwner($app)->create([
        'name' => 'watch',
        'command' => './watch.sh',
        'restart_policy' => 'always',
        'crash_notification' => 'none',
        'runtime' => ProcessRuntime::Systemd,
        'sort_order' => 1,
    ]);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell;

    $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeThrowingSiteCertificateInstaller)->handle($app);

    // Per the documented warning shape (warning_codes.php registry), the
    // tls_certificate_missing code must carry the process family and the
    // doctor next-command pointer. The installer threw on the main context,
    // so per-process install scripts must not have been issued for that
    // context.
    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0])->toMatchArray([
            'code' => 'process.tls_certificate_missing',
            'family' => 'process',
            'next_command' => 'doctor --family=process --restore',
        ])
        ->and($remoteShell->scripts)->toBe([]);
});

it('does not enact runtime units when an app has no process definitions', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
    ]);
    $app->setRelation('node', $node);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell;

    $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeRecordingSiteCertificateInstaller)->handle($app);

    expect($warnings)->toBe([])
        ->and($remoteShell->scripts)->toBe([]);
});

describe('runtime dispatcher', function (): void {
    it('does not install systemd units for a docker-runtime process and instead renders the Docker container', function (): void {
        $node = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
            'user' => 'orbit',
        ]);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        $app->setRelation('node', $node);

        OrbitProcess::factory()->forOwner($app)->create([
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'restart_policy' => 'always',
            'crash_notification' => 'none',
            'runtime' => ProcessRuntime::Docker,
            'sort_order' => 1,
        ]);

        $remoteShell = new ProcessRuntimeRecordingRemoteShell([
            // docker network inspect → missing
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such network', durationMs: 1),
            // docker network create
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            // docker container inspect → missing
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such container', durationMs: 1),
            // docker run
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeRecordingSiteCertificateInstaller)->handle($app);

        expect($warnings)->toBe([])
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, 'systemctl enable')))->toBeFalse()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, '/etc/systemd/system/orbit_docs_main_queue.service')))->toBeFalse()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, 'docker create')))->toBeTrue()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, 'orbit_docs_main_queue')))->toBeTrue()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, "--entrypoint 'sh'")))->toBeTrue();
    });

    it('installs systemd units for a systemd-runtime process on a static app', function (): void {
        $node = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);

        $app = App::factory()->create([
            'name' => 'marketing',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/marketing',
            'runtime_kind' => AppRuntimeKind::Static,
        ]);
        $app->setRelation('node', $node);

        OrbitProcess::factory()->forOwner($app)->create([
            'name' => 'watch',
            'command' => './watch.sh',
            'restart_policy' => 'always',
            'crash_notification' => 'none',
            'runtime' => ProcessRuntime::Systemd,
            'sort_order' => 1,
        ]);

        $remoteShell = new ProcessRuntimeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeRecordingSiteCertificateInstaller)->handle($app);

        expect($warnings)->toBe([])
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, 'docker run -d') || str_contains($s, 'docker create')))->toBeFalse()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, 'docker network')))->toBeFalse()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, '/etc/systemd/system/orbit_marketing_main_watch.service')))->toBeTrue();
    });
});

final class ProcessRuntimeRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}

final class ProcessRuntimeRecordingSiteCertificateInstaller implements SiteCertificateInstaller
{
    /**
     * @var list<string>
     */
    public array $hosts = [];

    public function ensureFor(Node $node, string $host): array
    {
        $this->hosts[] = $host;

        return $this->expectedPathsFor($node, $host);
    }

    public function expectedPathsFor(Node $node, string $host): array
    {
        return [
            'cert' => "/home/{$node->user}/.config/orbit/certs/{$host}.crt",
            'key' => "/home/{$node->user}/.config/orbit/certs/{$host}.key",
        ];
    }
}

final class ProcessRuntimeThrowingSiteCertificateInstaller implements SiteCertificateInstaller
{
    public function ensureFor(Node $node, string $host): array
    {
        throw new RuntimeException("Refused to install TLS certificate for {$host}.");
    }

    public function expectedPathsFor(Node $node, string $host): array
    {
        return [
            'cert' => "/home/{$node->user}/.config/orbit/certs/{$host}.crt",
            'key' => "/home/{$node->user}/.config/orbit/certs/{$host}.key",
        ];
    }
}
