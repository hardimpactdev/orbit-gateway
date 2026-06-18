<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROCESS_DESTROY_CALLER_WG_IP = '10.6.0.91';

function createProcessDestroyCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROCESS_DESTROY_CALLER_WG_IP,
        'wireguard_address' => PROCESS_DESTROY_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantProcessDestroyAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['process:remove'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProcessDestroyController', function (): void {
    it('removes process intent for authorized control callers with destructive consent', function (): void {
        $caller = createProcessDestroyCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessDestroyAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessDestroyRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('DELETE', '/api/processes/vite', [
            'app' => 'docs',
            'destructive_consent' => true,
        ], [], [], ['REMOTE_ADDR' => PROCESS_DESTROY_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process', [
                'name' => 'vite',
                'node' => $appNode->name,
                'app' => 'docs',
                'workspace' => null,
            ])
            ->assertJsonPath('success.data.removed_runtime_units', ['orbit_docs_main_vite'])
            ->assertJsonPath('success.meta.warnings', []);

        expect(Process::query()->where('name', 'vite')->exists())->toBeFalse();
    });

    it('removes node owned process intent with destructive consent', function (): void {
        $caller = createProcessDestroyCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantProcessDestroyAccess($caller, $node);
        Process::factory()->forOwner($node)->create([
            'name' => 'opencode-server',
            'runtime' => 'systemd',
            'tool' => 'opencode',
        ]);
        app()->instance(RemoteShell::class, new ProcessDestroyRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('DELETE', '/api/processes/opencode-server', [
            'node' => 'app-1',
            'destructive_consent' => true,
        ], [], [], ['REMOTE_ADDR' => PROCESS_DESTROY_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process', [
                'name' => 'opencode-server',
                'node' => 'app-1',
                'app' => null,
                'workspace' => null,
            ])
            ->assertJsonPath('success.data.removed_runtime_units', ['opencode-server']);

        expect(Process::query()->where('name', 'opencode-server')->exists())->toBeFalse();
    });

    it('removes workspace owned process intent with destructive consent', function (): void {
        $caller = createProcessDestroyCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessDestroyAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $workspace = Workspace::factory()->for($app)->create(['name' => 'feature-docs', 'path' => '/srv/docs-feature']);
        Process::factory()->forOwner($workspace)->create([
            'name' => 'worker',
            'runtime' => 'systemd',
        ]);
        app()->instance(RemoteShell::class, new ProcessDestroyRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('DELETE', '/api/processes/worker', [
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'destructive_consent' => true,
        ], [], [], ['REMOTE_ADDR' => PROCESS_DESTROY_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process', [
                'name' => 'worker',
                'node' => $appNode->name,
                'app' => 'docs',
                'workspace' => 'feature-docs',
            ])
            ->assertJsonPath('success.data.removed_runtime_units', ['orbit_docs_feature-docs_worker']);

        expect($workspace->processes()->where('name', 'worker')->exists())->toBeFalse();
    });

    it('requires authorization and destructive consent before deleting intent', function (array $payload, bool $grantAccess, int $status, string $code): void {
        $caller = createProcessDestroyCallerNode();
        $appNode = createTestAppHostNode();
        if ($grantAccess) {
            grantProcessDestroyAccess($caller, $appNode);
        }
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessDestroyRemoteShell([]));

        $response = $this->call('DELETE', '/api/processes/vite', $payload, [], [], ['REMOTE_ADDR' => PROCESS_DESTROY_CALLER_WG_IP]);

        $response->assertStatus($status)
            ->assertJsonPath('error.code', $code);

        expect(Process::query()->where('name', 'vite')->exists())->toBeTrue();
    })->with([
        'missing consent' => [['app' => 'docs'], true, 422, 'validation_failed'],
        'unauthorized' => [['app' => 'docs', 'destructive_consent' => true], false, 403, 'authorization_failed'],
    ]);

    it('denies app callers without a process remove grant before deleting intent', function (): void {
        $caller = createProcessDestroyCallerNode(role: 'app-dev');
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $caller->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessDestroyRemoteShell([]));

        $response = $this->call('DELETE', '/api/processes/vite', [
            'app' => 'docs',
            'destructive_consent' => true,
        ], [], [], ['REMOTE_ADDR' => PROCESS_DESTROY_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:remove');
    });

    it('returns process not found without cleanup', function (): void {
        createProcessDestroyCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessDestroyRemoteShell([]));

        $response = $this->call('DELETE', '/api/processes/vite', [
            'app' => 'docs',
            'destructive_consent' => true,
        ], [], [], ['REMOTE_ADDR' => PROCESS_DESTROY_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'process.not_found');
    });
});

final class ProcessDestroyRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
