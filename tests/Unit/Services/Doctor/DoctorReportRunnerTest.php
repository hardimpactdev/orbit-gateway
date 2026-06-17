<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Models\SchedulerState;
use App\Models\WireGuardPeer;
use App\Models\Workspace;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorScopeValidator;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\DevelopmentDnsMappingProbe;
use App\Services\Runtime\OrbitCaddyContainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Fakes\SiteCertificateInstallerFake;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $developmentDnsConfigDir = storage_path('framework/testing/doctor-runner-dns/'.bin2hex(random_bytes(6)));
    $developmentDnsMappingEnactor = new DevelopmentDnsMappingEnactor($developmentDnsConfigDir);

    app()->instance(DevelopmentDnsMappingEnactor::class, $developmentDnsMappingEnactor);
    app()->instance(DevelopmentDnsMappingProbe::class, new DevelopmentDnsMappingProbe($developmentDnsMappingEnactor));
});

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

function createDoctorRunnerAppHostNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => 'app-1',
        'status' => 'active',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

function markDoctorRunnerNodeSecurityBaselineClean(Node $node): void
{
    $node->forceFill([
        'user' => 'orbit',
        'host_key_type' => 'ed25519',
        'host_key_public' => 'ssh-ed25519 AAAATEST',
        'host_key_fingerprint' => 'SHA256:test',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ])->save();

    foreach (['v4', 'v6'] as $addressFamily) {
        FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => "orbit-public-ssh-deny-{$addressFamily}",
            'direction' => 'incoming',
            'action' => 'deny',
            'source' => 'any',
            'port' => '22',
            'protocol' => 'tcp',
            'source_hash' => hash('sha256', "orbit-public-ssh-deny-{$node->id}-{$addressFamily}"),
            'address_family' => $addressFamily,
            'interface' => 'public',
            'owner' => 'node-security',
            'protected' => true,
        ]);
    }
}

function createDoctorRunnerUpdateGateway(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => 'updates-gateway',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.6.0.1',
        'wireguard_address' => null,
        'user' => 'orbit',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'status' => 'active',
        'settings' => [],
    ]);

    markDoctorRunnerNodeSecurityBaselineClean($node);

    return $node;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function doctorRunnerUpdateProbeResult(array $overrides = []): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'installed' => true,
            'auto_exists' => true,
            'unattended_exists' => true,
            'auto_hash_ok' => true,
            'unattended_hash_ok' => true,
            'dry_run_exit' => 0,
            'last_run_status' => 'completed',
            'reboot_required' => false,
            'reboot_required_packages' => [],
            ...$overrides,
        ], JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 1,
    );
}

function fakeDoctorRunnerSchedulerSwarmService(string $replicas = '1/1', ?string $image = null): void
{
    $image ??= 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    Process::preventStrayProcesses();
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "{$image}\n"),
        "docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'" => Process::result(output: "{$replicas}\n"),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
    ]);
}

describe('DoctorReportRunner app family extra container detection', function (): void {
    it('emits app.runtime_container_extra when the node has an orbit-owned app runtime container without a matching active app record', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:present\norbit-app-orphan-docs\torphan-docs\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        expect($report['healthy'])->toBeFalse()
            ->and(collect($report['issues'])->firstWhere('key', 'app.runtime_container_extra'))->toMatchArray([
                'family' => 'app',
                'node' => 'app-1',
                'key' => 'app.runtime_container_extra',
                'kind' => 'extra',
            ])
            ->and(collect($report['issues'])->firstWhere('key', 'app.runtime_container_extra')['detail'])->toMatchArray([
                'app' => 'orphan-docs',
                'container' => 'orbit-app-orphan-docs',
            ]);
    });

    it('ignores containers whose orbit.app slug maps to an active app record on the node', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "docs\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t0\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:present\norbit-app-docs\tdocs\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        expect(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_container_extra');
    });

    it('removes the orphan app runtime container under restore mode via the apps fixer', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $inspectPayload = json_encode(['State' => ['Running' => true], 'Config' => ['Labels' => []]], JSON_THROW_ON_ERROR);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:present\norbit-app-orphan-docs\torphan-docs\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $inspectPayload, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'app',
                'node' => 'app-1',
                'key' => 'app.runtime_container_extra',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($report['actions'][0]['details'])->toMatchArray([
                'app' => 'orphan-docs',
                'container' => 'orbit-app-orphan-docs',
                'outcome' => 'removed',
            ])
            ->and(collect($shell->scripts)->contains(fn (string $s): bool => str_contains($s, "docker rm -f 'orbit-app-orphan-docs'")))->toBeTrue();
    });

    it('records a failure action when removal of the extra app runtime container fails', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $inspectPayload = json_encode(['State' => ['Running' => true], 'Config' => ['Labels' => []]], JSON_THROW_ON_ERROR);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:present\norbit-app-orphan-docs\torphan-docs\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $inspectPayload, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'container in use', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        expect($report['healthy'])->toBeFalse()
            ->and(collect($report['actions'])->firstWhere('key', 'app.runtime_container_extra'))->toMatchArray([
                'family' => 'app',
                'node' => 'app-1',
                'key' => 'app.runtime_container_extra',
                'mode' => 'restore',
                'status' => 'failed',
            ]);
    });

    it('emits app.runtime_config_extra when an orphan /etc/orbit/apps/<slug>.ini exists without an active app record', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:present\n/etc/orbit/apps/orphan-docs.ini\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_config_extra');
        expect($issue)->toMatchArray([
            'family' => 'app',
            'node' => 'app-1',
            'key' => 'app.runtime_config_extra',
            'kind' => 'extra',
        ])
            ->and($issue['detail'])->toMatchArray([
                'app' => 'orphan-docs',
                'path' => '/etc/orbit/apps/orphan-docs.ini',
            ]);
    });

    it('removes the orphan managed runtime config under restore mode via the apps fixer', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:present\n/etc/orbit/apps/orphan-docs.ini\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:present\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_config_extra');
        expect($action)->toMatchArray([
            'family' => 'app',
            'node' => 'app-1',
            'key' => 'app.runtime_config_extra',
            'mode' => 'restore',
            'status' => 'completed',
        ])
            ->and($action['details'])->toMatchArray([
                'app' => 'orphan-docs',
                'path' => '/etc/orbit/apps/orphan-docs.ini',
                'outcome' => 'removed',
            ])
            ->and(collect($shell->scripts)->contains(fn (string $s): bool => str_contains($s, "sudo rm -f '/etc/orbit/apps/orphan-docs.ini'")))->toBeTrue();
    });

    it('records a failed action when the runtime config probe fails for an unknown reason during app.runtime_config_extra restore', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:present\n/etc/orbit/apps/orphan-docs.ini\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-config-probe:error\n", stderr: 'sudo: no tty present', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_config_extra');

        expect($action)->not->toBeNull()
            ->and($action['status'])->toBe('failed')
            ->and($action['details']['app'])->toBe('orphan-docs');
    });

    it('records a failed action when the docker inspect probe fails for an unknown reason during app.runtime_container_extra restore', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:present\norbit-app-orphan-docs\torphan-docs\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock.',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_container_extra');

        expect($action)->not->toBeNull()
            ->and($action['status'])->toBe('failed')
            ->and($action['details']['app'])->toBe('orphan-docs');
    });

    it('emits app.runtime_container_missing when an active PHP app has a stopped FrankenPHP runtime container', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);
        // Per-app introspect output: path/root present, docker available,
        // container exists + matches spec, container_running=false, runtime
        // config present and matches.
        $expectedSpecHash = hash('sha256', '');

        $perAppStdout = "docs\t1\t1\t1\t1\t1\t1\t0\t1\t1\t1\t1\t1\t0\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);
        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_container_missing');

        expect($issue)->not->toBeNull()
            ->and($issue['kind'])->toBe('missing')
            ->and($issue['detail']['reason'] ?? null)->toBe('container_stopped')
            ->and($issue['detail']['expected'] ?? null)->toBe('orbit-app-docs');
    });

    it('restarts a stopped runtime container via restore mode for an active PHP app', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);
        $perAppStdout = "docs\t1\t1\t1\t1\t1\t1\t0\t1\t1\t1\t1\t1\t0\n";

        $stoppedInspect = json_encode([
            'State' => ['Running' => false],
            'Config' => ['Labels' => []],
        ], JSON_THROW_ON_ERROR);

        $shell = new DoctorReportRunnerRemoteShell([
            // probe: per-app introspect, then node-level docker ls + ini find
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            // restore: manager.apply() network inspect → container inspect → image inspect → rm + run
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $stoppedInspect, stderr: '', durationMs: 1),
            // image inspect ok
            new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
            // manager runs docker rm because labels don't match the expected spec hash (Labels=[])
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_container_missing');

        expect($action)->not->toBeNull()
            ->and($action['status'])->toBe('completed')
            ->and(collect($shell->scripts)->contains(fn (string $s): bool => str_contains($s, 'docker run -d') && str_contains($s, "'orbit-app-docs'")))->toBeTrue();
    });

    it('reports app.runtime_container_extra for an active static app whose stale FrankenPHP container still exists', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->static()->create([
            'name' => 'marketing',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/marketing',
            'document_root' => 'public',
        ]);
        // Per-app probe runs against the static app, returns benign snapshot
        // (no PHP-app checks fire). Node-level container ls returns the stale
        // orbit-app-marketing container.
        $perAppStdout = "marketing\t1\t1\t1\t1\t0\t1\t0\t0\t0\t0\t1\t1\t0\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:present\norbit-app-marketing\tmarketing\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_container_extra');

        expect($issue)->not->toBeNull()
            ->and($issue['detail'])->toMatchArray([
                'app' => 'marketing',
                'container' => 'orbit-app-marketing',
            ]);
    });

    it('reports app.runtime_config_extra for an active static app whose stale managed runtime config still exists', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->static()->create([
            'name' => 'marketing',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/marketing',
            'document_root' => 'public',
        ]);
        $perAppStdout = "marketing\t1\t1\t1\t1\t0\t1\t0\t0\t0\t0\t1\t1\t0\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:present\n/etc/orbit/apps/marketing.ini\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_config_extra');

        expect($issue)->not->toBeNull()
            ->and($issue['detail'])->toMatchArray([
                'app' => 'marketing',
                'path' => '/etc/orbit/apps/marketing.ini',
            ]);
    });

    it('removes a static-app stale FrankenPHP container under restore mode', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->static()->create([
            'name' => 'marketing',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/marketing',
            'document_root' => 'public',
        ]);
        $perAppStdout = "marketing\t1\t1\t1\t1\t0\t1\t0\t0\t0\t0\t1\t1\t0\n";
        $inspectPayload = json_encode(['State' => ['Running' => true], 'Config' => ['Labels' => []]], JSON_THROW_ON_ERROR);

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:present\norbit-app-marketing\tmarketing\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            // fixer removeExtra: inspect succeeds, then docker rm
            new RemoteShellResult(exitCode: 0, stdout: $inspectPayload, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_container_extra');

        expect($action)->not->toBeNull()
            ->and($action['status'])->toBe('completed')
            ->and($action['details']['outcome'])->toBe('removed')
            ->and(collect($shell->scripts)->contains(fn (string $s): bool => str_contains($s, "docker rm -f 'orbit-app-marketing'")))->toBeTrue();
    });

    it('emits app.php_version_unavailable when the selected FrankenPHP image is missing on the owning node', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);
        // path=1, root=1, root_inside_path=1, docker_available=1,
        // container_exists=0, container_spec_matches=0, container_running=0,
        // system_user_exists=0, fs_permissions_ok=0,
        // runtime_config_exists=0, runtime_config_matches=0,
        // runtime_image_available=0 (the new failing signal)
        $perAppStdout = "docs\t1\t1\t1\t1\t0\t0\t0\t0\t0\t0\t0\t0\t0\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.php_version_unavailable');

        expect($issue)->not->toBeNull()
            ->and($issue['detail']['php_version'])->toBe('8.5')
            ->and($issue['detail']['expected_image'])->toBe('dunglas/frankenphp:1-php8.5-bookworm')
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_container_missing')
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_container_mismatch');
    });

    it('does not mark app.php_version_unavailable as restorable in doctor restore mode', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);
        $perAppStdout = "docs\t1\t1\t1\t1\t0\t0\t0\t0\t0\t0\t0\t0\t0\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.php_version_unavailable');

        expect($action)->not->toBeNull()
            ->and($action['status'])->toBe('skipped')
            ->and($action['details']['reason'] ?? null)->toBe('mode_not_supported');
    });

    it('maps unknown image-probe failure with no container to documented app.runtime_container_missing (NOT app.php_version_unavailable, NOT a new probe-failed key)', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);
        // runtime_image_available=0, runtime_image_probe_failed=1, container_exists=0:
        // probe column 14 (probe_failed) is 1 — must surface as the documented
        // restorable `app.runtime_container_missing`, NOT a new undocumented
        // `app.runtime_image_probe_failed` key.
        $perAppStdout = "docs\t1\t1\t1\t1\t0\t0\t0\t0\t0\t0\t0\t0\t1\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_container_missing');

        expect($issue)->not->toBeNull()
            ->and($issue['kind'])->toBe('missing')
            ->and($issue['restorable'] ?? false)->toBeTrue()
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.php_version_unavailable')
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_image_probe_failed')
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_container_mismatch');
    });

    it('maps unknown image-probe failure WITH an existing mismatched container to documented app.runtime_container_mismatch', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);
        // runtime_image_available=0, runtime_image_probe_failed=1, container_exists=1, container_spec_matches=0, container_running=1:
        // probe-failed must surface as the documented `app.runtime_container_mismatch`
        // so doctor restore can re-attempt apply via the manager.
        $perAppStdout = "docs\t1\t1\t1\t1\t1\t0\t1\t0\t0\t0\t0\t0\t1\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_container_mismatch');

        expect($issue)->not->toBeNull()
            ->and($issue['kind'])->toBe('divergent')
            ->and($issue['restorable'] ?? false)->toBeTrue()
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.php_version_unavailable')
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_image_probe_failed')
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_container_missing');
    });

    it('emits app.runtime_config_probe_failed when the runtime config directory probe fails for an unknown reason (does not silently hide orphan configs)', function (): void {
        $node = createDoctorRunnerAppHostNode();
        // No App rows — orphan scan would normally walk the directory. The
        // probe sentinel reports an unknown error, so doctor MUST surface a
        // dedicated probe-failed drift rather than treating it as a clean
        // empty snapshot.
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:error sudo: a terminal is required to read the password\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_config_probe_failed');

        expect($issue)->not->toBeNull()
            ->and($issue['kind'])->toBe('unverifiable')
            ->and($issue['detail']['path'])->toBe('/etc/orbit/apps')
            ->and($issue['detail']['error'])->toContain('terminal')
            ->and($issue['restorable'] ?? false)->toBeTrue()
            // Must NOT silently absorb the error as a clean empty list.
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_config_extra');
    });

    it('does NOT emit app.runtime_config_probe_failed when the directory is proven absent (clean empty snapshot)', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        expect(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_config_probe_failed')
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_config_extra');
    });

    it('clears app.runtime_config_probe_failed under restore mode when re-probe succeeds', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            // probe: introspectNode absent (no docker orphan extras possible),
            // introspectNodeRuntimeConfigs error
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:error sudo: not allowed\n", stderr: '', durationMs: 1),
            // restore: re-probe now succeeds (absent — clean recovery)
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_config_probe_failed');

        expect($action)->not->toBeNull()
            ->and($action['status'])->toBe('completed')
            ->and($action['details']['status'] ?? null)->toBe('absent');
    });

    it('emits app.runtime_config_probe_failed (NOT raises) when the runtime config directory probe shell returns a non-zero exit without a sentinel — SSH/transport flake must not abort the doctor run', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            // Non-zero exit without a sentinel — mirrors SSH/transport flakes
            // and remote-shell construction errors that the previous
            // throw=>true path would have raised out of doctor.
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'ssh: connect to host: connection refused', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        // Must not throw.
        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_config_probe_failed');

        expect($issue)->not->toBeNull()
            ->and($issue['kind'])->toBe('unverifiable')
            ->and($issue['detail']['error'])->toContain('connection refused')
            ->and($issue['restorable'] ?? false)->toBeTrue()
            // Must NOT silently absorb the error as a clean empty list.
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_config_extra');
    });

    it('clears app.runtime_config_probe_failed on restore when an earlier non-zero remote shell recovers to absent', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            // probe: container scan absent, config scan throws (non-zero exit)
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'remote shell pipeline broke', durationMs: 1),
            // restore: re-probe now succeeds with proven-absent directory.
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_config_probe_failed');

        expect($action)->not->toBeNull()
            ->and($action['status'])->toBe('completed')
            ->and($action['details']['status'] ?? null)->toBe('absent');
    });

    it('emits app.runtime_container_probe_failed (NOT raises) when the node-wide docker container scan fails for an unknown reason — does NOT abort the doctor run and does NOT hide stale extras', function (): void {
        $node = createDoctorRunnerAppHostNode();
        // No App rows. Container scan fails with daemon-down stderr; doctor
        // MUST surface a dedicated probe-failed drift rather than throwing
        // out of the run or treating the error as a clean empty snapshot.
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:error Cannot connect to the Docker daemon at unix:///var/run/docker.sock\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_container_probe_failed');

        expect($issue)->not->toBeNull()
            ->and($issue['kind'])->toBe('unverifiable')
            ->and($issue['detail']['error'])->toContain('Cannot connect to the Docker daemon')
            ->and($issue['restorable'] ?? false)->toBeTrue()
            // Must NOT silently absorb the error as a clean empty list.
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_container_extra');
    });

    it('emits app.runtime_container_probe_failed when the remote shell call itself fails (SSH/transport error must not abort doctor)', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            // Non-zero exit without a sentinel — mirrors SSH transport flakes.
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'ssh: connect to host: connection refused', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        // Must not raise.
        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_container_probe_failed');

        expect($issue)->not->toBeNull()
            ->and($issue['kind'])->toBe('unverifiable')
            ->and($issue['detail']['error'])->toContain('connection refused')
            ->and($issue['restorable'] ?? false)->toBeTrue();
    });

    it('does NOT emit app.runtime_container_probe_failed when docker is proven absent on the node (clean empty snapshot)', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        expect(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_container_probe_failed')
            ->and(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_container_extra');
    });

    it('clears app.runtime_container_probe_failed under restore mode when the docker scan succeeds again', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            // probe: introspectNode error, introspectNodeRuntimeConfigs absent
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:error docker daemon flake\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            // restore: re-probe now succeeds (absent — no docker on node, clean recovery)
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_container_probe_failed');

        expect($action)->not->toBeNull()
            ->and($action['status'])->toBe('completed')
            ->and($action['details']['status'] ?? null)->toBe('absent');
    });

    it('records a failed action when the runtime container scan still fails on restore', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:error daemon flake\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            // restore: re-probe ALSO fails
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:error still flaking\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_container_probe_failed');

        expect($action)->not->toBeNull()
            ->and($action['status'])->toBe('failed')
            ->and($action['details']['error'] ?? '')->toContain('still flaking');
    });

    it('records a failed action when the runtime config directory probe still fails on restore', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:error sudo: not allowed\n", stderr: '', durationMs: 1),
            // restore: re-probe ALSO fails
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:error sudo: still not allowed\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_config_probe_failed');

        expect($action)->not->toBeNull()
            ->and($action['status'])->toBe('failed')
            ->and($action['details']['error'] ?? '')->toContain('not allowed');
    });
});

describe('DoctorReportRunner', function (): void {
    it('does not probe or fix workspace PHP-FPM pools for PHP apps because workspaces use Docker containers', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
        ]);
        Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '/home/orbit/apps/docs/.worktrees/feature',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "feature\t1\t1\t1\t1\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['workspace']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 0,
                'skipped' => 0,
            ])
            ->and(collect($report['actions'])->pluck('key')->all())->not->toContain('workspace.fpm_config_mismatch');
    });

    it('suppresses resolved issues when a supported restore completes', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $shell = new DoctorReportRunnerRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        fakeDoctorRunnerSchedulerSwarmService(replicas: '0/1');

        $report = app(DoctorReportRunner::class)->run($gateway, mode: 'restore', families: ['schedule']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'failed' => 0,
                'skipped' => 0,
            ])
            ->and($report['issues'])->toBe([])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'schedule',
                'node' => 'gateway-1',
                'key' => 'schedule.scheduler_stopped',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts)->toBe([]);

        Process::assertRan("docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'");
        Process::assertRan("docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'");
        Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
    });

    it('suppresses resolved scheduler image drift when restore updates the Swarm service image', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $shell = new DoctorReportRunnerRemoteShell([]);
        $desiredImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.4@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        app()->instance(RemoteShell::class, $shell);
        config()->set('orbit.updates.gateway_image', $desiredImage);
        Process::preventStrayProcesses();
        Process::fake([
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n"),
            "docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'" => Process::result(output: "1/1\n"),
            "docker service update --detach=true --image '{$desiredImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" => Process::result(),
            "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
        ]);

        SchedulerState::factory()->create([
            'node_id' => $gateway->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);

        $report = app(DoctorReportRunner::class)->run($gateway, mode: 'restore', families: ['schedule']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'failed' => 0,
                'skipped' => 0,
            ])
            ->and($report['issues'])->toBe([])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'schedule',
                'node' => 'gateway-1',
                'key' => 'schedule.scheduler_image_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts)->toBe([]);

        Process::assertRan("docker service update --detach=true --image '{$desiredImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'");
        Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
    });

    it('installs missing tools through restore mode family dispatch', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $decoyNode = Node::factory()->appDev()->create(['name' => 'decoy-app']);
        NodeTool::factory()->create(['node_id' => $decoyNode->id, 'name' => 'composer']);
        $node = createDoctorRunnerAppHostNode();
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: json_encode([
                'name' => 'composer',
                'installed' => true,
                'path' => '/usr/local/bin/composer',
                'version' => 'Composer version 2.8.0',
                'state' => 'unknown',
                'config_exists' => null,
                'config_hash' => null,
                'secret_exists' => null,
                'secret_hash' => null,
                'container_exists' => null,
                'container_state' => null,
                'container_spec_hash' => null,
            ], JSON_THROW_ON_ERROR)."\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['tool']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])->toBe([])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.capability_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts)->toHaveCount(3)
            ->and($shell->nodeNames[1])->toBe('app-1')
            ->and($shell->scripts[1])->toContain('composer-setup.php');
    });

    it('restores duplicate-name firewall rules on the scoped node', function (): void {
        $decoyNode = Node::factory()->appDev()->create(['name' => 'decoy-app', 'platform' => 'ubuntu']);
        $node = Node::factory()->appDev()->create(['name' => 'target-app', 'platform' => 'ubuntu']);

        FirewallRule::factory()->create([
            'node_id' => $decoyNode->id,
            'name' => 'local-https',
        ]);
        FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-https',
        ]);

        $missingFirewallStatus = <<<'TXT'
Status: active

To                         Action      From
--                         ------      ----
TXT;
        $restoredFirewallStatus = <<<'TXT'
Status: active

To                         Action      From
--                         ------      ----
[ 1] 443/tcp                    ALLOW IN    Anywhere                   # test firewall rule
TXT;

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $missingFirewallStatus, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $restoredFirewallStatus, stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['firewall_rule']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'firewall_rule',
                'node' => 'target-app',
                'key' => 'firewall_rule.rule_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->nodeNames[1])->toBe('target-app');
    });

    it('restores agent tool proxy routes through restore mode family dispatch', function (): void {
        $node = Node::factory()->create([
            'name' => 'agent-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'host' => '10.6.0.11',
            'wireguard_address' => '10.6.0.11',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'agent',
            'status' => 'active',
            'settings' => ['tld' => 'agent'],
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
            'credentials' => ['fields' => ['url' => 'https://openclaw.agent']],
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => str_repeat('b', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:9999'],
                'upstream' => 'http://127.0.0.1:9999',
                'owner_name' => 'openclaw',
            ],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "/usr/local/bin/openclaw\tOpenClaw 1.0\trunning\t\t\t\t\t\t\t\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['tool']);
        $route = ProxyRoute::query()->where('domain', 'openclaw.agent')->firstOrFail();

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])->toBe([])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'agent-1',
                'key' => 'tool.agent_route_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($route->config['upstream'])->toBe('http://host.docker.internal:8080');
    });

    it('restores missing process runtime units through restore mode family dispatch', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
        ]);
        \App\Models\Process::factory()->forOwner($app)->create([
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => 'on_failure',
            'crash_notification' => 'none',
            'sort_order' => 1,
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit_docs_main_vite\t0\t0\t0\t0\n__notifier\t1\t1\t1\t1\t1\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])->toBe([])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'process',
                'node' => 'app-1',
                'key' => 'process.runtime_unit_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts[2])->toContain("sudo tee '/etc/systemd/system/orbit_docs_main_vite.service' >/dev/null");
    });

    it('restores missing process runtime units for the app named in the runtime unit', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $docs = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
        ]);
        $blog = App::factory()->for($node, 'node')->create([
            'name' => 'blog',
            'path' => '/home/orbit/apps/blog',
        ]);
        \App\Models\Process::factory()->forOwner($docs)->create([
            'name' => 'vp-dev',
            'command' => 'npm run docs',
            'restart_policy' => 'on_failure',
            'crash_notification' => 'none',
            'sort_order' => 1,
        ]);
        \App\Models\Process::factory()->forOwner($blog)->create([
            'name' => 'vp-dev',
            'command' => 'npm run blog',
            'restart_policy' => 'on_failure',
            'crash_notification' => 'none',
            'sort_order' => 1,
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit_docs_main_vp-dev\t1\t1\t1\t1\n__notifier\t1\t1\t1\t1\t1\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit_blog_main_vp-dev\t0\t0\t0\t0\n__notifier\t1\t1\t1\t1\t1\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'process',
                'node' => 'app-1',
                'key' => 'process.runtime_unit_missing',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => ['app' => 'blog', 'process' => 'vp-dev'],
            ])
            ->and($shell->scripts[4])->toContain("sudo tee '/etc/systemd/system/orbit_blog_main_vp-dev.service' >/dev/null");
    });

    it('restores missing node-owned process runtime units through restore mode family dispatch', function (): void {
        $node = Node::factory()->database()->create([
            'name' => 'metrics-worker-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'user' => 'orbit',
        ]);
        \App\Models\Process::factory()->forOwner($node)->create([
            'name' => 'node-exporter',
            'runtime' => ProcessRuntime::Systemd,
            'command' => 'node_exporter --web.listen-address=0.0.0.0:9100',
            'restart_policy' => 'always',
            'crash_notification' => 'none',
            'sort_order' => 1,
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "node-exporter\t0\t0\t0\t0\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])->toBe([])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'process',
                'node' => 'metrics-worker-1',
                'key' => 'process.runtime_unit_missing',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'node' => 'metrics-worker-1',
                    'process' => 'node-exporter',
                    'runtime_unit' => 'node-exporter',
                ],
            ])
            ->and($shell->scripts[2])->toContain("sudo tee '/etc/systemd/system/node-exporter.service' >/dev/null");
    });

    it('restores missing node-owned docker swarm process runtime units through restore mode family dispatch', function (): void {
        $node = Node::factory()->database()->create([
            'name' => 'metrics-worker-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'user' => 'orbit',
        ]);
        \App\Models\Process::factory()->forOwner($node)->create([
            'name' => 'grafana',
            'runtime' => ProcessRuntime::DockerSwarm,
            'command' => 'grafana server --homepath=/usr/share/grafana',
            'restart_policy' => 'always',
            'crash_notification' => 'none',
            'runtime_config' => [
                'service_name' => 'orbit-grafana',
                'image' => 'grafana/grafana:12.0.1',
                'labels' => [
                    'orbit.managed' => 'true',
                    'orbit.process' => 'grafana',
                    'orbit.process.spec_hash' => str_repeat('a', 64),
                ],
            ],
            'sort_order' => 1,
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'no such service: orbit-grafana', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])->toBe([])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'process',
                'node' => 'metrics-worker-1',
                'key' => 'process.runtime_unit_missing',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'node' => 'metrics-worker-1',
                    'process' => 'grafana',
                    'runtime_unit' => 'orbit-grafana',
                ],
            ])
            ->and($shell->scripts[2])->toContain('docker service create')
            ->and($shell->scripts[2])->toContain("--name 'orbit-grafana'")
            ->and($shell->scripts[2])->toContain('--replicas 0')
            ->and($shell->scripts[2])->toContain("'grafana/grafana:12.0.1'");
    });

    it('restores missing orbit-caddy containers through restore mode family dispatch', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $node = createDoctorRunnerAppHostNode();
        $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'config' => ['container' => $container->spec()],
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "/usr/bin/docker\tDocker version 27.0.0\tmissing\t\t\t\t\t0\tmissing\t\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "/usr/bin/docker\tDocker version 27.0.0\trunning\t\t\t\t\t1\trunning\t{$container->specHash()}\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['tool']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])->toBe([])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.container_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts[1])->toContain('docker container inspect')
            ->and($shell->scripts[1])->toContain('10.6.0.50:80:80')
            ->and($shell->scripts[1])->toContain('orbit.caddy.spec_hash');
    });

    it('does not require gh on gateway-only no-source nodes', function (): void {
        $gateway = Node::factory()->gateway()->create([
            'name' => 'gateway-no-source',
            'status' => 'active',
        ]);

        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $report = app(DoctorReportRunner::class)->probe($gateway, families: ['tool']);

        $toolNames = collect($report['issues'])
            ->map(fn (array $issue): mixed => data_get($issue, 'detail.tool'))
            ->filter()
            ->values()
            ->all();

        expect($toolNames)->not->toContain('gh');
    });

    it('suppresses resolved tool version issues when a safe update restore completes', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $node = createDoctorRunnerAppHostNode();
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_version' => '3.0',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "/usr/local/bin/composer\tComposer version 2.8.0\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['tool']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])->toBe([])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.version_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts[1])->toContain('composer self-update');
    });

    it('keeps the issue visible and records a failed action when Swarm scheduler restore fails', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $shell = new DoctorReportRunnerRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        Process::preventStrayProcesses();
        Process::fake([
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n"),
            "docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'" => Process::result(output: "0/1\n"),
            "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(exitCode: 1, errorOutput: "scheduler scale failed\n"),
        ]);

        $report = app(DoctorReportRunner::class)->run($gateway, mode: 'restore', families: ['schedule']);

        expect($report['healthy'])->toBeFalse()
            ->and($report['summary'])->toMatchArray([
                'issues' => 1,
                'fixed' => 0,
                'failed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'][0])->toMatchArray([
                'family' => 'schedule',
                'node' => 'gateway-1',
                'key' => 'schedule.scheduler_stopped',
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'schedule',
                'node' => 'gateway-1',
                'key' => 'schedule.scheduler_stopped',
                'mode' => 'restore',
                'status' => 'failed',
            ])
            ->and($report['actions'][0]['details']['error'])->toContain('scheduler scale failed')
            ->and($shell->scripts)->toBe([]);
    });

    it('restores supported node role baseline drift through the node converger', function (): void {
        File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());

        $node = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'host' => '10.0.0.1',
            'wireguard_address' => '10.6.0.5',
        ]);
        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);
        markDoctorRunnerNodeSecurityBaselineClean($node);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['node']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'node',
                'node' => 'app-1',
                'key' => 'node.role_baseline_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and(app(DevelopmentDnsMappingEnactor::class)->configDir().'/test.conf')->toBeFile();
    });

    it('skips unsupported node role drift during restore', function (): void {
        $node = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'host' => '10.0.0.1',
            'wireguard_address' => '10.6.0.5',
        ]);
        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);
        markDoctorRunnerNodeSecurityBaselineClean($node);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => [],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['node']);

        expect($report['healthy'])->toBeFalse()
            ->and($report['summary'])->toMatchArray([
                'issues' => 1,
                'fixed' => 0,
                'skipped' => 1,
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'node',
                'node' => 'app-1',
                'key' => 'node.role_settings_invalid',
                'mode' => 'restore',
                'status' => 'skipped',
            ]);
    });

    it('supports the database connection family on app nodes but not database-only nodes', function (): void {
        $appNode = createDoctorRunnerAppHostNode();
        $databaseNode = Node::factory()->database()->create(['status' => 'active']);

        $runner = app(DoctorReportRunner::class);

        expect($runner->supportedFamilies())->toContain('database_connection')
            ->and($runner->categoriesForNode($appNode))->toContain('database_connection')
            ->and($runner->categoriesForNode($databaseNode))->not->toContain('database_connection')
            ->and($runner->categoriesForNode($databaseNode))->toContain('process');
    });

    it('supports the process family on every node with an active role assignment', function (string $role): void {
        $node = Node::factory()->create(['status' => 'active']);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => 'active',
        ]);

        $categories = app(DoctorReportRunner::class)->categoriesForNode($node);

        expect($categories)->toContain('process');
    })->with([
        'gateway' => ['gateway'],
        'vpn' => ['vpn'],
        'router' => ['router'],
        'app-dev' => ['app-dev'],
        'app-prod' => ['app-prod'],
        'database' => ['database'],
        'agent' => ['agent'],
        'ingress' => ['ingress'],
        'websocket' => ['websocket'],
        's3' => ['s3'],
        'metrics' => ['metrics'],
        'analytics' => ['analytics'],
    ]);

    it('does not support the process family on nodes without an active role assignment', function (): void {
        $node = Node::factory()->create(['status' => 'active']);

        $categories = app(DoctorReportRunner::class)->categoriesForNode($node);

        expect($categories)->toBe(['node']);
    });

    it('allows explicit process doctor scope on role-bearing nodes', function (): void {
        $node = Node::factory()->agent()->create(['status' => 'active']);

        $failure = app(DoctorScopeValidator::class)->validate(
            families: ['process'],
            runner: app(DoctorReportRunner::class),
            target: $node,
        );

        expect($failure)->toBeNull();
    });

    it('does not mark database connection unverifiable issues as adoptable', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-unverifiable');
        File::ensureDirectoryExists($path);

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'missing env', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'missing env', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->probe($node, ['database_connection']);
        $issue = collect($report['issues'])->firstWhere('key', 'database_connection.unverifiable');

        expect($issue)->not->toBeNull()
            ->and($issue['adoptable'] ?? null)->toBeFalse();
    });

    it('restores database connection env drift through family dispatch', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-restore');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['database_connection']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'fixed' => 2,
                'skipped' => 0,
            ])
            ->and(collect($report['actions'])->pluck('family')->unique()->all())->toBe(['database_connection'])
            ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, 'base64 -d')))->toBeTrue();
    });

    it('restores missing database connection target mappings through family dispatch', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-target-missing');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
        ]));

        $probe = app(DoctorReportRunner::class)->probe($node, ['database_connection']);
        $issue = collect($probe['issues'])->firstWhere('key', 'database_connection.target_missing');

        expect($issue)->not->toBeNull()
            ->and($issue['restorable'] ?? null)->toBeTrue();

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['database_connection']);

        expect($report['healthy'])->toBeTrue()
            ->and(DatabaseConnectionTarget::query()
                ->where('database_connection_id', $connection->id)
                ->where('app_id', $app->id)
                ->where('env_prefix', 'DB')
                ->exists())->toBeTrue();
    });

    it('adopts database connection env state for registered apps through family dispatch', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-adopt');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'adopt', families: ['database_connection']);

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'adopted' => 1,
                'skipped' => 0,
            ])
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'database_connection',
                'node' => 'app-1',
                'mode' => 'adopt',
            ])
            ->and(DatabaseConnection::query()->where('slug', 'docs')->exists())->toBeTrue();
    });

    it('adopt mode updates gateway database connections from mismatched env without restoring env files', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-adopt-mismatch');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\nDB_HOST=observed-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=observed-user\nDB_PASSWORD=observed-secret\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'stored-host',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'stored-user',
            'credentials' => ['password' => 'stored-secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);
        $original = File::get($path.'/.env');
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\nDB_HOST=observed-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=observed-user\nDB_PASSWORD=observed-secret\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\nDB_HOST=observed-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=observed-user\nDB_PASSWORD=observed-secret\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\nDB_HOST=observed-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=observed-user\nDB_PASSWORD=observed-secret\n", stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'adopt', families: ['database_connection']);

        $connection->refresh();

        expect($report['healthy'])->toBeTrue()
            ->and($report['summary'])->toMatchArray([
                'issues' => 0,
                'adopted' => 1,
                'skipped' => 0,
            ])
            ->and(File::get($path.'/.env'))->toBe($original)
            ->and($connection)->toMatchArray([
                'driver' => 'mysql',
                'host' => 'observed-host',
                'port' => 3306,
                'database' => 'docs_v2',
                'username' => 'observed-user',
            ])
            ->and($connection->credentials)->toMatchArray(['password' => 'observed-secret']);
    });

    it('returns a failed action when database connection restore throws', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-restore-failure');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['database_connection']);
        $failedAction = collect($report['actions'])->firstWhere('status', 'failed');

        expect($report['healthy'])->toBeFalse()
            ->and($report['summary']['failed'])->toBeGreaterThanOrEqual(1)
            ->and($failedAction)->toMatchArray([
                'family' => 'database_connection',
                'node' => 'app-1',
                'mode' => 'restore',
                'status' => 'failed',
            ])
            ->and($failedAction['key'])->toBeIn(['database_connection.env_missing', 'database_connection.env_mismatch'])
            ->and($failedAction)->toMatchArray([
                'mode' => 'restore',
                'status' => 'failed',
            ])
            ->and($failedAction['details']['error'] ?? null)->toContain('permission denied');
    });

    it('reports updates with the shared node updates key and specific issue code', function (): void {
        $node = createDoctorRunnerUpdateGateway();
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            doctorRunnerUpdateProbeResult(['auto_hash_ok' => false]),
        ]));

        $report = app(DoctorReportRunner::class)->probe($node, ['node'], 'node.updates');

        expect($report['healthy'])->toBeFalse()
            ->and($report['issues'][0])->toMatchArray([
                'family' => 'node',
                'node' => 'updates-gateway',
                'key' => 'node.updates',
                'code' => 'node.updates_config_mismatch',
                'restorable' => true,
            ]);
    });

    it('keeps updates reboot drift after restore re-probes a completed config action', function (): void {
        $node = createDoctorRunnerUpdateGateway();
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            doctorRunnerUpdateProbeResult(['auto_hash_ok' => false]),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'completed', stderr: '', durationMs: 1),
            doctorRunnerUpdateProbeResult(['reboot_required' => true]),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['node'], key: 'node.updates');

        expect($report['healthy'])->toBeFalse()
            ->and($report['actions'][0])->toMatchArray([
                'family' => 'node',
                'node' => 'updates-gateway',
                'key' => 'node.updates',
                'code' => 'node.updates_config_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($report['issues'][0])->toMatchArray([
                'family' => 'node',
                'node' => 'updates-gateway',
                'key' => 'node.updates',
                'code' => 'node.updates_reboot_required',
                'restorable' => false,
            ]);
    });
});

// ---------------------------------------------------------------------------
// S3 role: category mapping + s3 probe dispatch
// ---------------------------------------------------------------------------

describe('DoctorReportRunner s3 role categories', function (): void {
    it('resolves s3 role to node, tool, and proxy categories', function (): void {
        $runner = app(DoctorReportRunner::class);

        $categories = $runner->categoriesForRole('s3');

        expect($categories)->toBe(['node', 'tool', 'proxy']);
    });

    it('resolves s3 node to node, tool, and proxy categories when it has an active s3 role', function (): void {
        $node = Node::factory()->create([
            'name' => 's3-node-cat',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.30',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 's3',
            'status' => 'active',
            'settings' => ['data_path' => '/srv/orbit/s3/data'],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $runner = app(DoctorReportRunner::class);

        $categories = $runner->categoriesForNode($node);

        expect($categories)->toContain('node')
            ->and($categories)->toContain('tool')
            ->and($categories)->toContain('proxy');
    });

    it('dispatches tool.seaweedfs.row_missing when an s3 node has no seaweedfs tool row', function (): void {
        $node = Node::factory()->create([
            'name' => 's3-disp-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.31',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 's3',
            'status' => 'active',
            'settings' => ['data_path' => '/srv/orbit/s3/data'],
        ]);
        // No seaweedfs NodeTool row
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $report = app(DoctorReportRunner::class)->probe($node, families: ['tool']);

        $keys = collect($report['issues'])->pluck('key')->all();
        expect($keys)->toContain('tool.seaweedfs.row_missing');
    });

    it('dispatches node.s3.wireguard_missing when an s3 node has no wireguard address', function (): void {
        $node = Node::factory()->create([
            'name' => 's3-disp-wg',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => null,
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 's3',
            'status' => 'active',
            'settings' => ['data_path' => '/srv/orbit/s3/data'],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $report = app(DoctorReportRunner::class)->probe($node, families: ['node']);

        $keys = collect($report['issues'])->pluck('key')->all();
        expect($keys)->toContain('node.s3.wireguard_missing');
    });

    it('dispatches node.s3_data_path_invalid when an s3 node has a relative data_path setting', function (): void {
        $node = Node::factory()->create([
            'name' => 's3-disp-dp',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.32',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 's3',
            'status' => 'active',
            'settings' => ['data_path' => 'relative/invalid/path'],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $report = app(DoctorReportRunner::class)->probe($node, families: ['node']);

        $keys = collect($report['issues'])->pluck('key')->all();
        expect($keys)->toContain('node.s3_data_path_invalid');
    });
});

describe('DoctorReportRunner metrics role categories', function (): void {
    it('resolves metrics role to node, tool, process, and proxy categories', function (): void {
        $runner = app(DoctorReportRunner::class);

        $categories = $runner->categoriesForRole('metrics');

        expect($categories)->toBe(['node', 'tool', 'process', 'proxy']);
    });

    it('resolves a dedicated metrics node to node, tool, process, and proxy categories', function (): void {
        $node = Node::factory()->create([
            'name' => 'metrics-node-cat',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.60',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'metrics',
            'status' => 'active',
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $runner = app(DoctorReportRunner::class);

        $categories = $runner->categoriesForNode($node);

        expect($categories)->toContain('node')
            ->and($categories)->toContain('tool')
            ->and($categories)->toContain('process')
            ->and($categories)->toContain('proxy');
    });

    it('includes metrics categories when the metrics role is co-located with gateway', function (): void {
        $node = Node::factory()->create([
            'name' => 'gateway-metrics-node-cat',
            'status' => 'active',
            'platform' => 'debian_12',
            'wireguard_address' => '10.6.0.61',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'metrics',
            'status' => 'active',
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $runner = app(DoctorReportRunner::class);

        $categories = $runner->categoriesForNode($node);

        expect($categories)->toContain('node')
            ->and($categories)->toContain('schedule')
            ->and($categories)->toContain('tool')
            ->and($categories)->toContain('process')
            ->and($categories)->toContain('proxy');
    });
});

final class DoctorReportRunnerRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<string>
     */
    public array $nodeNames = [];

    /**
     * @param  list<RemoteShellResult|Throwable>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->nodeNames[] = $node->name;
        $result = array_shift($this->results);

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
