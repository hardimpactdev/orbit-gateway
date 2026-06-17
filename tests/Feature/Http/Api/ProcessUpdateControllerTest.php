<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

const PROCESS_UPDATE_CALLER_WG_IP = '10.6.0.90';

function createProcessUpdateCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROCESS_UPDATE_CALLER_WG_IP,
        'wireguard_address' => PROCESS_UPDATE_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantProcessUpdateAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['process:edit'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProcessUpdateController', function (): void {
    it('updates process intent for authorized control callers', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'command' => 'npm run dev']);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('PATCH', '/api/processes/vite', [
            'app' => 'docs',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => 'on_failure',
        ], [], [], ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process.command', 'npm run dev -- --host=0.0.0.0')
            ->assertJsonPath('success.data.changed', ['command', 'restart_policy'])
            ->assertJsonPath('success.meta.warnings', []);
    });

    it('rejects unauthorized callers before changing intent', function (): void {
        createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'command' => 'npm run dev']);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([]));

        $response = $this->call('PATCH', '/api/processes/vite', [
            'app' => 'docs',
            'command' => 'npm run dev -- --host=0.0.0.0',
        ], [], [], ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:edit');

        expect(Process::query()->where('name', 'vite')->value('command'))->toBe('npm run dev');
    });

    it('denies app callers without a process edit grant before changing intent', function (): void {
        $caller = createProcessUpdateCallerNode(role: 'app-dev');
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $caller->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'command' => 'npm run dev']);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([]));

        $response = $this->call('PATCH', '/api/processes/vite', [
            'app' => 'docs',
            'command' => 'npm run dev',
        ], [], [], ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:edit');
    });

    it('persists and returns the runtime field when supplied', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'runtime' => 'docker']);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('PATCH', '/api/processes/queue', [
            'app' => 'docs',
            'runtime' => 'systemd',
        ], [], [], ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.changed', ['runtime']);

        expect(Process::query()->where('name', 'queue')->value('runtime')->value)->toBe('systemd');
    });

    it('updates node owned systemd process intent', function (): void {
        $caller = createProcessUpdateCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantProcessUpdateAccess($caller, $node);
        Process::factory()->forOwner($node)->create([
            'name' => 'opencode-server',
            'command' => 'opencode serve',
            'runtime' => 'systemd',
            'tool' => 'opencode',
        ]);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('PATCH', '/api/processes/opencode-server', [
            'node' => 'app-1',
            'command' => 'opencode serve -a',
            'runtime' => 'systemd',
        ], [], [], ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process.node', 'app-1')
            ->assertJsonPath('success.data.process.app', null)
            ->assertJsonPath('success.data.process.workspace', null)
            ->assertJsonPath('success.data.process.command', 'opencode serve -a')
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.process.tool', 'opencode')
            ->assertJsonPath('success.data.runtime_units.0', ['name' => 'opencode-server', 'context' => 'node']);

        expect(Process::query()->where('name', 'opencode-server')->value('command'))->toBe('opencode serve -a');
    });

    it('updates workspace owned process intent', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $workspace = Workspace::factory()->for($app)->create(['name' => 'feature-docs', 'path' => '/srv/docs-feature']);
        Process::factory()->forOwner($workspace)->create([
            'name' => 'worker',
            'command' => 'php artisan queue:work',
            'runtime' => 'systemd',
        ]);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('PATCH', '/api/processes/worker', [
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'command' => 'php artisan horizon',
        ], [], [], ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process.app', 'docs')
            ->assertJsonPath('success.data.process.workspace', 'feature-docs')
            ->assertJsonPath('success.data.process.command', 'php artisan horizon')
            ->assertJsonPath('success.data.runtime_units.0', ['name' => 'orbit_docs_feature-docs_worker', 'context' => 'feature-docs']);

        expect($workspace->processes()->where('name', 'worker')->value('command'))->toBe('php artisan horizon');
    });

    it('rejects invalid runtime values with the documented validation envelope', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'runtime' => 'docker']);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([]));

        $response = $this->call('PATCH', '/api/processes/queue', [
            'app' => 'docs',
            'runtime' => 'podman',
        ], [], [], ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'podman')
            ->assertJsonPath('error.meta.allowed', ['docker', 'docker-swarm', 'systemd']);

        expect(Process::query()->where('name', 'queue')->value('runtime')->value)->toBe('docker');
    });

    it('rejects supervisor for app scoped process updates before runtime side effects', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'runtime' => 'docker']);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('PATCH', '/api/processes/queue', [
            'app' => 'docs',
            'runtime' => 'supervisor',
        ], [], [], ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'supervisor')
            ->assertJsonPath('error.meta.allowed', ['docker', 'docker-swarm', 'systemd']);

        expect(Process::query()->where('name', 'queue')->value('runtime')->value)->toBe('docker')
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects docker swarm for app scoped process updates before runtime side effects', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'runtime' => 'docker']);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('PATCH', '/api/processes/queue', [
            'app' => 'docs',
            'runtime' => 'docker-swarm',
        ], [], [], ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker-swarm')
            ->assertJsonPath('error.meta.reason', 'docker_swarm_requires_node_owned_process');

        expect(Process::query()->where('name', 'queue')->value('runtime')->value)->toBe('docker')
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects docker for app scoped host-command process updates before runtime side effects', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'queue', 'runtime' => 'systemd']);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('PATCH', '/api/processes/queue', [
            'app' => 'docs',
            'runtime' => 'docker',
        ], [], [], ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker')
            ->assertJsonPath('error.meta.reason', 'docker_runtime_requires_service_or_managed_process');

        expect(Process::query()->where('name', 'queue')->value('runtime')->value)->toBe('systemd')
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects docker for workspace scoped host-command process updates before runtime side effects', function (): void {
        $caller = createProcessUpdateCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessUpdateAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $workspace = Workspace::factory()->for($app)->create(['name' => 'feature-docs']);
        Process::factory()->forOwner($workspace)->create(['name' => 'queue', 'runtime' => 'systemd']);
        $remoteShell = new ProcessUpdateRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('PATCH', '/api/processes/queue', [
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'runtime' => 'docker',
        ], [], [], ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker')
            ->assertJsonPath('error.meta.reason', 'docker_runtime_requires_service_or_managed_process');

        expect($workspace->processes()->where('name', 'queue')->value('runtime')->value)->toBe('systemd')
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('returns validation and not found errors', function (array $payload, string $processName, int $status, string $code): void {
        createProcessUpdateCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'command' => 'npm run dev']);
        app()->instance(RemoteShell::class, new ProcessUpdateRemoteShell([]));

        $response = $this->call('PATCH', "/api/processes/{$processName}", $payload, [], [], ['REMOTE_ADDR' => PROCESS_UPDATE_CALLER_WG_IP]);

        $response->assertStatus($status)
            ->assertJsonPath('error.code', $code);
    })->with([
        'missing app' => [['command' => 'npm run dev'], 'vite', 422, 'validation_failed'],
        'no editable fields' => [['app' => 'docs'], 'vite', 422, 'validation_failed'],
        'invalid restart' => [['app' => 'docs', 'restart_policy' => 'sometimes'], 'vite', 422, 'validation_failed'],
        'not found' => [['app' => 'docs', 'command' => 'php artisan queue:work'], 'queue', 404, 'process.not_found'],
    ]);
});

final class ProcessUpdateRemoteShell implements RemoteShell
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
