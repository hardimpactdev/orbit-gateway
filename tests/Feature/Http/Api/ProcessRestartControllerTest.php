<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROCESS_RESTART_CALLER_WG_IP = '10.6.0.93';

function createProcessRestartCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROCESS_RESTART_CALLER_WG_IP,
        'wireguard_address' => PROCESS_RESTART_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantProcessRestartAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['process:restart'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProcessRestartController', function (): void {
    it('restarts a process for authorized control callers and records the event', function (): void {
        $caller = createProcessRestartCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessRestartAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessRestartApiRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('POST', '/api/processes/restart', [
            'app' => 'docs',
            'name' => 'vite',
        ], [], [], ['REMOTE_ADDR' => PROCESS_RESTART_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.runtimes.0.runtime_unit', 'orbit_docs_main_vite')
            ->assertJsonPath('success.data.runtimes.0.events.0.type', 'stopped');

        expect(ProcessEvent::query()->where('event', 'started')->exists())->toBeTrue();
    });

    it('returns partial runtime failure data', function (): void {
        createProcessRestartCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'sort_order' => 10]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'sort_order' => 20]);
        app()->instance(RemoteShell::class, new ProcessRestartApiRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1),
        ]));

        $response = $this->call('POST', '/api/processes/restart', [
            'app' => 'docs',
        ], [], [], ['REMOTE_ADDR' => PROCESS_RESTART_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'process.runtime_action_failed')
            ->assertJsonPath('error.meta.partial_state', 'partially_restarted')
            ->assertJsonPath('error.data.runtimes.1.state', 'failed');
    });

    it('requires authorization before runtime side effects', function (): void {
        createProcessRestartCallerNode();
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        $remoteShell = new ProcessRestartApiRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/processes/restart', [
            'app' => 'docs',
            'name' => 'vite',
        ], [], [], ['REMOTE_ADDR' => PROCESS_RESTART_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:restart');

        expect($remoteShell->scripts)->toBe([]);
    });
});

final class ProcessRestartApiRemoteShell implements RemoteShell
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
