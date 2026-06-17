<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\SchedulerState;
use App\Models\Workspace;
use App\Services\Platform\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(PlatformDetector::class, new class extends PlatformDetector
    {
        public function detectLocal(): string
        {
            return 'linux';
        }
    });
});

const DOCTOR_RUN_CALLER_WG_IP = '10.6.0.94';

function createDoctorRunCallerNode(array $overrides = [], string $role = 'gateway'): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => DOCTOR_RUN_CALLER_WG_IP,
        'wireguard_address' => DOCTOR_RUN_CALLER_WG_IP,
        'platform' => 'ubuntu'], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

describe('DoctorRunController', function (): void {
    it('runs verify mode and returns a doctor report', function (): void {
        createDoctorRunCallerNode(['platform' => 'linux']);
        $response = $this->call('POST', '/api/doctor/run', [
            'families' => ['node'],
            'mode' => 'verify'], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', true)
            ->assertJsonPath('success.data.doctor.scope.families', ['node']);
    });

    it('accepts the proxy family scope when targeting an app node', function (): void {
        createDoctorRunCallerNode(['platform' => 'linux']);
        createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(perRouteStdout: '', nodeLevelStdout: ''));

        $response = $this->call('POST', '/api/doctor/run', [
            'families' => ['proxy'],
            'mode' => 'verify',
            'node' => 'app-1'], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', true)
            ->assertJsonPath('success.data.doctor.scope.families', ['proxy']);
    });

    it('filters API verify results by exact issue key', function (): void {
        createDoctorRunCallerNode(['platform' => 'linux']);
        Node::factory()->create([
            'name' => 'incomplete-app',
            'status' => 'active',
            'platform' => null,
            'wireguard_address' => null]);

        $response = $this->call('POST', '/api/doctor/run', [
            'families' => ['node'],
            'node' => 'incomplete-app',
            'key' => 'node.record_incomplete'], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.scope.key', 'node.record_incomplete')
            ->assertJsonPath('success.data.doctor.issues.0.key', 'node.record_incomplete');
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->postJson('/api/doctor/run', ['families' => ['node']]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('restores firewall drift through the doctor fix endpoint', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active', 'platform' => 'ubuntu']);
        FirewallRule::factory()->create(['node_id' => $appNode->id, 'name' => 'local-vite', 'source' => '10.6.0.0/24', 'port' => '5173']);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell("Status: active\n\n     To                         Action      From\n     --                         ------      ----\n"));

        $response = $this->call('POST', '/api/doctor/fix', [
            'mode' => 'restore',
            'families' => ['firewall_rule'],
            'node' => 'app-1'], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.mode', 'restore')
            ->assertJsonPath('success.data.doctor.summary.fixed', 1)
            ->assertJsonPath('success.data.doctor.actions.0.status', 'completed');
    });

    it('restores proxy drift through the doctor fix endpoint', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173']]);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(perRouteStdout: "0\t\t\t\t0\t0\n", nodeLevelStdout: ''));

        $response = $this->call('POST', '/api/doctor/fix', [
            'mode' => 'restore',
            'families' => ['proxy'],
            'node' => 'app-1'], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.mode', 'restore')
            ->assertJsonPath('success.data.doctor.summary.fixed', 1)
            ->assertJsonPath('success.data.doctor.actions.0.status', 'completed');
    });

    it('dry-runs API restore without applying fixers', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173']]);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(perRouteStdout: "0\t\t\t\t0\t0\n", nodeLevelStdout: ''));

        $response = $this->call('POST', '/api/doctor/fix', [
            'mode' => 'restore',
            'families' => ['proxy'],
            'node' => 'app-1',
            'dry_run' => true], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.dry_run', true)
            ->assertJsonPath('success.data.doctor.summary.fixed', 0)
            ->assertJsonPath('success.data.doctor.actions.0.status', 'planned');
    });

    it('accepts the tool family scope and returns tool drift', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        NodeTool::factory()->create(['node_id' => $appNode->id, 'name' => 'composer']);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(perRouteStdout: '', exitCode: 1));

        $response = $this->call('POST', '/api/doctor/run', [
            'mode' => 'verify',
            'families' => ['tool'],
            'node' => 'app-1'], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', false)
            ->assertJsonPath('success.data.doctor.issues.0.key', 'tool.capability_missing');
    });

    it('accepts the app family scope and returns app drift', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public']);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell("docs\t0\t0\t1\t1\t0\t0\t0\t0\t0\t0\t0\t0\t0\n"));

        $response = $this->call('POST', '/api/doctor/run', [
            'mode' => 'verify',
            'families' => ['app'],
            'node' => 'app-1'], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', false)
            ->assertJsonPath('success.data.doctor.scope.families', ['app'])
            ->assertJsonPath('success.data.doctor.issues.0.key', 'app.path_missing');
    });

    it('accepts the workspace family scope and returns workspace drift', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs']);
        Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '/home/orbit/apps/docs/.worktrees/feature']);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell("feature\t0\t1\t0\t0\n"));

        $response = $this->call('POST', '/api/doctor/run', [
            'mode' => 'verify',
            'families' => ['workspace'],
            'node' => 'app-1'], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', false)
            ->assertJsonPath('success.data.doctor.scope.families', ['workspace'])
            ->assertJsonPath('success.data.doctor.issues.0.key', 'workspace.path_missing');
    });

    it('accepts the process family scope and returns process drift', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs']);
        Process::factory()->forOwner($app)->create([
            'name' => 'queue',
            'runtime' => ProcessRuntime::Systemd]);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(perRouteStdout: '', exitCode: 1));

        $response = $this->call('POST', '/api/doctor/run', [
            'mode' => 'verify',
            'families' => ['process'],
            'node' => 'app-1'], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', false)
            ->assertJsonPath('success.data.doctor.scope.families', ['process'])
            ->assertJsonPath('success.data.doctor.issues.0.key', 'process.runtime_backend_unavailable');
    });

    it('restores tool drift through the doctor fix endpoint', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'expected_version' => '2.9',
        ]);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(
            "/usr/bin/caddy\t2.8.4\tstopped\n",
            perRouteStdouts: [
                // First call: probe returns installed but mismatched version (2.8.4 vs expected 2.9)
                "/usr/bin/caddy\t2.8.4\tstopped\n",
                // Second call: update script runs and succeeds
                '',
                // Third call: re-probe after fix confirms correct version
                "/usr/bin/caddy\t2.9.0\tstopped\n",
            ],
        ));

        $response = $this->call('POST', '/api/doctor/fix', [
            'mode' => 'restore',
            'families' => ['tool'],
            'node' => 'app-1'], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.mode', 'restore')
            ->assertJsonPath('success.data.doctor.summary.fixed', 1)
            ->assertJsonPath('success.data.doctor.actions.0.status', 'completed');
    });

    it('accepts the schedule family scope and returns schedule health', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $appNode->id]);
        Schedule::factory()->forApp($app)->create();
        SchedulerState::factory()->create([
            'node_id' => $appNode->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now()]);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell("running\n"));

        $response = $this->call('POST', '/api/doctor/run', [
            'mode' => 'verify',
            'families' => ['schedule'],
            'node' => 'app-1'], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', true)
            ->assertJsonPath('success.data.doctor.scope.families', ['schedule']);
    });

    it('requires doctor write authority for fix mode requests', function (): void {
        createDoctorRunCallerNode(role: 'app-dev');

        $response = $this->call('POST', '/api/doctor/fix', [
            'mode' => 'adopt',
            'families' => ['firewall_rule']], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'doctor:adopt')
            ->assertJsonPath('error.meta.mode', 'adopt');
    });

    it('allows app-node fix mode requests with explicit doctor authority', function (): void {
        $caller = createDoctorRunCallerNode(['platform' => 'linux'], role: 'app-dev');
        NodeAccess::query()->updateOrCreate(
            [
                'consumer_node_id' => $caller->id,
                'serving_node_id' => $caller->id],
            [
                'permissions' => ['doctor:adopt'],
                'custom_permissions' => ['doctor:adopt']],
        );

        $response = $this->call('POST', '/api/doctor/fix', [
            'mode' => 'adopt',
            'families' => ['node']], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.mode', 'adopt')
            ->assertJsonPath('success.data.doctor.scope.families', ['node']);
    });
});

final class DoctorRunRemoteShell implements RemoteShell
{
    /** @var list<string> */
    private array $perRouteStdouts;

    /**
     * @param  list<string>  $perRouteStdouts
     */
    public function __construct(
        string $perRouteStdout,
        private readonly string $nodeLevelStdout = '',
        private readonly int $exitCode = 0,
        array $perRouteStdouts = [],
    ) {
        $this->perRouteStdouts = $perRouteStdouts === [] ? [$perRouteStdout] : $perRouteStdouts;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, 'docker container ls')) {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }

        if (str_contains($script, "dir='/etc/orbit/apps'")) {
            // Emit the probe sentinel so introspectNodeRuntimeConfigs reports
            // the directory as proven-absent instead of treating empty stdout
            // as an unknown sudo/probe failure.
            return new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1);
        }

        if (str_contains($script, 'orbit-proxy-doctor:caddy-container-probe')) {
            // Default: orbit-caddy container is healthy on serving nodes.
            return new RemoteShellResult(exitCode: 0, stdout: "available\ttrue\ttrue\n", stderr: '', durationMs: 1);
        }

        $isNodeLevel = str_contains($script, '/etc/caddy/sites/*.caddy');
        $stdout = $isNodeLevel
            ? $this->nodeLevelStdout
            : (array_shift($this->perRouteStdouts) ?? '');

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $stdout, stderr: '', durationMs: 1);
    }
}
