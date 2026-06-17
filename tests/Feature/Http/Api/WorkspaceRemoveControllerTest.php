<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const WORKSPACE_REMOVE_CALLER_WG_IP = '10.6.0.81';

function createWorkspaceRemoveCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => WORKSPACE_REMOVE_CALLER_WG_IP,
        'wireguard_address' => WORKSPACE_REMOVE_CALLER_WG_IP,
    ], $overrides);

    if ($role === 'gateway') {
        return createTestGatewayNode($attributes);
    }

    return Node::factory()->create($attributes);
}

function grantWorkspaceRemoveAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['workspace:remove'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('WorkspaceRemoveController', function (): void {
    it('removes workspace intent for authorized callers', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $targetNode);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $targetNode->id,
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature-api',
        ]);

        ProxyRoute::factory()->create([
            'node_id' => $targetNode->id,
            'domain' => 'feature-api.docs.test',
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        OrbitProcess::factory()->forOwner($workspace)->create([
            'name' => 'frankenphp-docs-feature-api',
            'command' => 'frankenphp',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'container_name' => 'orbit-ws-docs-feature-api',
                'php_ini_path' => '/etc/orbit/workspaces/docs-feature-api.ini',
            ],
        ]);

        $shell = new WorkspaceRemoveApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '{"Id":"abc"}', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'orbit-container-config-probe:present', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'orbit-container-config-probe:absent', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'DELETE',
            '/api/workspaces/feature-api?app=docs',
            [
                'keep_files' => false,
                'destructive_consent' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => WORKSPACE_REMOVE_CALLER_WG_IP],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.name', 'feature-api')
            ->assertJsonPath('success.data.action', 'removed')
            ->assertJsonPath('success.data.proxy_routes_removed', 1)
            ->assertJsonPath('success.meta.kept_files', false);

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'feature-api.docs.test')->exists())->toBeFalse()
            ->and(OrbitProcess::query()->where('name', 'frankenphp-docs-feature-api')->exists())->toBeFalse()
            ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, "docker rm -f 'orbit-ws-docs-feature-api'")))->toBeTrue()
            ->and(collect($shell->scripts)->contains(fn (string $script): bool => str_contains($script, "sudo rm -f '/etc/orbit/workspaces/docs-feature-api.ini'")))->toBeTrue();
    });

    it('requires destructive consent before removing workspace intent', function (): void {
        $caller = createWorkspaceRemoveCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantWorkspaceRemoveAccess($caller, $targetNode);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $targetNode->id,
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature-api',
        ]);

        app()->instance(RemoteShell::class, new WorkspaceRemoveApiSequencedRemoteShell([]));

        $response = $this->call('DELETE', '/api/workspaces/feature-api?app=docs', [], [], [], ['REMOTE_ADDR' => WORKSPACE_REMOVE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force');

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue();
    });

    it('rejects workspace removal when the caller cannot access the app node', function (): void {
        createWorkspaceRemoveCallerNode();
        $targetNode = createTestAppHostNode([
            'name' => 'app-1',
            'status' => 'active',
        ]);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $targetNode->id,
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature-api',
        ]);

        app()->instance(RemoteShell::class, new WorkspaceRemoveApiSequencedRemoteShell([]));

        $response = $this->call('DELETE', '/api/workspaces/feature-api?app=docs', [
            'destructive_consent' => true,
        ], [], [], ['REMOTE_ADDR' => WORKSPACE_REMOVE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'workspace:remove');

        expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue();
    });
});

final class WorkspaceRemoveApiSequencedRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
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
