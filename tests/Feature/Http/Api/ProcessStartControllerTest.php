<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROCESS_START_CALLER_WG_IP = '10.6.0.92';

function createProcessStartCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROCESS_START_CALLER_WG_IP,
        'wireguard_address' => PROCESS_START_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantProcessStartAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['process:start'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProcessStartController', function (): void {
    it('starts a process for authorized control callers and records the event', function (): void {
        $caller = createProcessStartCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessStartAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessStartApiRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('POST', '/api/processes/start', [
            'app' => 'docs',
            'name' => 'vite',
        ], [], [], ['REMOTE_ADDR' => PROCESS_START_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.runtimes.0.runtime_unit', 'orbit_docs_main_vite')
            ->assertJsonPath('success.data.runtimes.0.event.type', 'started')
            ->assertJsonPath('success.meta', []);

        expect(ProcessEvent::query()->where('event', 'started')->exists())->toBeTrue();
    });

    it('allows an app-node caller for its own workspace context through the gateway API', function (): void {
        $appNode = createProcessStartCallerNode(role: 'app-dev');
        grantProcessStartAccess($appNode, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessStartApiRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('POST', '/api/processes/start', [
            'workspace' => 'feature-docs',
            'name' => 'vite',
        ], [], [], ['REMOTE_ADDR' => PROCESS_START_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.runtimes.0.workspace', 'feature-docs')
            ->assertJsonPath('success.data.runtimes.0.runtime_unit', 'orbit_docs_feature-docs_vite');
    });

    it('starts a workspace owned process for workspace context', function (): void {
        $appNode = createProcessStartCallerNode(role: 'app-dev');
        grantProcessStartAccess($appNode, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()->forOwner($workspace)->create([
            'name' => 'frankenphp-docs-feature-docs',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => ['container_name' => 'orbit-ws-docs-feature-docs'],
        ]);
        app()->instance(RemoteShell::class, new ProcessStartApiRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('POST', '/api/processes/start', [
            'workspace' => 'feature-docs',
            'name' => 'frankenphp-docs-feature-docs',
        ], [], [], ['REMOTE_ADDR' => PROCESS_START_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.runtimes.0.node', $appNode->name)
            ->assertJsonPath('success.data.runtimes.0.app', 'docs')
            ->assertJsonPath('success.data.runtimes.0.workspace', 'feature-docs')
            ->assertJsonPath('success.data.runtimes.0.runtime_unit', 'orbit-ws-docs-feature-docs');
    });

    it('starts a node owned process for node context', function (): void {
        createProcessStartCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'app-1']);
        Process::factory()->forOwner($node)->create([
            'name' => 'opencode-server',
            'runtime' => ProcessRuntime::Systemd,
            'tool' => 'opencode',
            'command' => 'opencode serve --hostname 0.0.0.0',
        ]);
        app()->instance(RemoteShell::class, new ProcessStartApiRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('POST', '/api/processes/start', [
            'node' => 'app-1',
            'name' => 'opencode-server',
        ], [], [], ['REMOTE_ADDR' => PROCESS_START_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.runtimes.0.node', 'app-1')
            ->assertJsonPath('success.data.runtimes.0.app', null)
            ->assertJsonPath('success.data.runtimes.0.workspace', null)
            ->assertJsonPath('success.data.runtimes.0.runtime_unit', 'opencode-server');
    });

    it('returns partial runtime failure data', function (): void {
        $caller = createProcessStartCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'sort_order' => 10]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'sort_order' => 20]);
        app()->instance(RemoteShell::class, new ProcessStartApiRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1),
        ]));

        $response = $this->call('POST', '/api/processes/start', [
            'app' => 'docs',
        ], [], [], ['REMOTE_ADDR' => PROCESS_START_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'process.runtime_action_failed')
            ->assertJsonPath('error.meta.partial_state', 'partially_started')
            ->assertJsonPath('error.data.runtimes.1.state', 'failed');

        expect($caller)->toBeInstanceOf(Node::class);
    });

    it('rejects unsupported persisted runtimes before runtime side effects', function (): void {
        createProcessStartCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $process = Process::factory()->forOwner($app)->create(['name' => 'vite']);
        DB::table('processes')->where('id', $process->id)->update(['runtime' => 'docker-swarm']);
        $remoteShell = new ProcessStartApiRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/processes/start', [
            'app' => 'docs',
            'name' => 'vite',
        ], [], [], ['REMOTE_ADDR' => PROCESS_START_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'process.unsupported_runtime')
            ->assertJsonPath('error.meta.process', 'vite')
            ->assertJsonPath('error.meta.runtime', 'docker-swarm')
            ->assertJsonPath('error.meta.reason', 'docker_swarm_requires_node_owned_process');

        expect($remoteShell->scripts)->toBe([]);
    });

    it('validates all selected process runtimes before bulk runtime side effects', function (): void {
        createProcessStartCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'sort_order' => 10]);
        $process = Process::factory()->forOwner($app)->create(['name' => 'vite', 'sort_order' => 20]);
        DB::table('processes')->where('id', $process->id)->update(['runtime' => 'docker-swarm']);
        $remoteShell = new ProcessStartApiRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/processes/start', [
            'app' => 'docs',
        ], [], [], ['REMOTE_ADDR' => PROCESS_START_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'process.unsupported_runtime')
            ->assertJsonPath('error.meta.process', 'vite')
            ->assertJsonPath('error.meta.runtime', 'docker-swarm')
            ->assertJsonPath('error.meta.reason', 'docker_swarm_requires_node_owned_process');

        expect($remoteShell->scripts)->toBe([]);
    });

    it('requires authorization before runtime side effects', function (): void {
        createProcessStartCallerNode();
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        $remoteShell = new ProcessStartApiRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/processes/start', [
            'app' => 'docs',
            'name' => 'vite',
        ], [], [], ['REMOTE_ADDR' => PROCESS_START_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:start');

        expect($remoteShell->scripts)->toBe([]);
    });

    it('denies callers without a process start grant before runtime side effects', function (): void {
        createProcessStartCallerNode();
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        $remoteShell = new ProcessStartApiRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/processes/start', [
            'app' => 'docs',
            'name' => 'vite',
        ], [], [], ['REMOTE_ADDR' => PROCESS_START_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:start');

        expect($remoteShell->scripts)->toBe([]);
    });
});

final class ProcessStartApiRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
        public array $scripts = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
