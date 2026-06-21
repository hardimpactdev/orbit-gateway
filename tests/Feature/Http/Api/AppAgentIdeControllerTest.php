<?php

declare(strict_types=1);

use App\Contracts\AgentIdeMessageAdapter;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(RemoteShell::class, new AppAgentIdeControllerRemoteShell);
});

const APP_AGENT_IDE_CALLER_WG_IP = '10.6.0.98';

function createAppAgentIdeCallerNode(array $overrides = [], ?string $role = null): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_AGENT_IDE_CALLER_WG_IP,
        'wireguard_address' => APP_AGENT_IDE_CALLER_WG_IP,
    ], $overrides));

    if ($role !== null) {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    return $node;
}

/**
 * @param  list<string>  $permissions
 */
function grantAppAgentIdeAccess(Node $caller, Node $appNode, array $permissions = ['app:agent']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function postAppAgentIdeJson(string $uri, array $data, array $server = []): TestResponse
{
    return test()->call(
        'POST',
        $uri,
        $data,
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server),
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

if (! class_exists('AppAgentIdeControllerRemoteShell')) {
    final class AppAgentIdeControllerRemoteShell implements RemoteShell
    {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    }
}

describe('AppAgentIdeController', function (): void {
    it('sets an app agent IDE default for an authorized control caller', function (): void {
        $caller = createAppAgentIdeCallerNode();
        $appNode = Node::factory()->appDev()->create([
            'name' => 'app-1',
            'agent_ide_config' => ['adapter' => 'polyscope'],
        ]);
        grantAppAgentIdeAccess($caller, $appNode);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
        ]);

        $response = postAppAgentIdeJson('/api/apps/docs/agent-ide', [
            'agent_ide' => 'opencode',
        ], ['REMOTE_ADDR' => APP_AGENT_IDE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.agent_ide.adapter', 'opencode')
            ->assertJsonPath('success.data.agent_ide.source', 'app')
            ->assertJsonPath('success.data.agent_ide.effective_adapter', 'opencode')
            ->assertJsonPath('success.data.cleanup.workspaces_removed', [])
            ->assertJsonPath('success.data.action', 'set');

        expect(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBe(['adapter' => 'opencode']);
    });

    it('clears an app override with inherit and reports the node effective adapter', function (): void {
        createAppAgentIdeCallerNode(role: 'gateway');
        $appNode = Node::factory()->appDev()->create([
            'name' => 'app-1',
            'agent_ide_config' => ['adapter' => 'polyscope'],
        ]);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'agent_ide_config' => ['adapter' => 'opencode'],
        ]);

        $response = postAppAgentIdeJson('/api/apps/docs/agent-ide', [
            'agent_ide' => 'inherit',
        ], ['REMOTE_ADDR' => APP_AGENT_IDE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.agent_ide.adapter', null)
            ->assertJsonPath('success.data.agent_ide.source', 'node')
            ->assertJsonPath('success.data.agent_ide.effective_adapter', 'polyscope');

        expect(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBeNull();
    });

    it('logs activity for a successful app agent IDE write', function (): void {
        $caller = createAppAgentIdeCallerNode();
        $appNode = Node::factory()->appDev()->create([
            'name' => 'app-1',
        ]);
        grantAppAgentIdeAccess($caller, $appNode);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
        ]);

        $response = postAppAgentIdeJson('/api/apps/docs/agent-ide', [
            'agent_ide' => 'opencode',
        ], ['REMOTE_ADDR' => APP_AGENT_IDE_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:POST /apps/{app}/agent-ide');
        expect($entry->subject_type)->toBe(App::class);
        expect($entry->subject_id)->toBe($app->id);
        expect($entry->description)->toBe('App docs agent IDE set to opencode');
        expect($entry->properties->get('type'))->toBe('write');
        expect($entry->properties->get('target_app'))->toBe('docs');
        expect($entry->properties->get('agent_ide'))->toBe('opencode');
        expect($entry->properties->get('action'))->toBe('set');
    });

    it('rejects callers without app:agent before mutation', function (): void {
        $caller = createAppAgentIdeCallerNode();
        $appNode = Node::factory()->appDev()->create([
            'name' => 'app-1',
        ]);
        grantAppAgentIdeAccess($caller, $appNode, ['app:read']);

        App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
        ]);

        $response = postAppAgentIdeJson('/api/apps/docs/agent-ide', [
            'agent_ide' => 'opencode',
        ], ['REMOTE_ADDR' => APP_AGENT_IDE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:agent')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBeNull();
    });

    it('returns validation errors for missing and unsupported adapters', function (array $data, string $code, string $field): void {
        createAppAgentIdeCallerNode(role: 'gateway');
        App::factory()->create([
            'name' => 'docs',
        ]);

        $response = postAppAgentIdeJson('/api/apps/docs/agent-ide', $data, ['REMOTE_ADDR' => APP_AGENT_IDE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', $code)
            ->assertJsonPath("error.meta.{$field}", $data['agent_ide'] ?? 'agent_ide');
    })->with([
        'missing adapter' => [[], 'validation_failed', 'field'],
        'unsupported adapter' => [['agent_ide' => 'unknown-ide'], 'app.unsupported_adapter', 'adapter'],
    ]);

    it('requires consent for destructive workspace cleanup without force', function (): void {
        createAppAgentIdeCallerNode(role: 'gateway');
        $app = App::factory()->create([
            'name' => 'docs',
            'agent_ide_config' => ['adapter' => 'opencode'],
        ]);

        Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'stale-ws',
            'path' => '/home/orbit/apps/docs/stale-ws',
        ]);

        app()->instance(AgentIdeMessageAdapter::class, new PruneAppActionTestAdapter);

        $response = postAppAgentIdeJson('/api/apps/docs/agent-ide', [
            'agent_ide' => 'polyscope',
        ], ['REMOTE_ADDR' => APP_AGENT_IDE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'workspace_cleanup_consent_required')
            ->assertJsonPath('error.meta.previous_adapter', 'opencode')
            ->assertJsonPath('error.meta.stale_workspaces', ['stale-ws']);

        expect(App::query()->where('name', 'docs')->value('agent_ide_config'))->toBe(['adapter' => 'polyscope']);
    });

    it('prunes stale workspaces when force is true', function (): void {
        createAppAgentIdeCallerNode(role: 'gateway');
        $app = App::factory()->create([
            'name' => 'docs',
            'agent_ide_config' => ['adapter' => 'opencode'],
        ]);

        Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'stale-ws',
            'path' => '/home/orbit/apps/docs/stale-ws',
        ]);

        app()->instance(AgentIdeMessageAdapter::class, new PruneAppActionTestAdapter);

        $response = postAppAgentIdeJson('/api/apps/docs/agent-ide', [
            'agent_ide' => 'polyscope',
            'force' => true,
        ], ['REMOTE_ADDR' => APP_AGENT_IDE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'set')
            ->assertJsonPath('success.data.previous_adapter', 'opencode')
            ->assertJsonPath('success.data.cleanup.workspaces_removed', ['stale-ws']);

        expect(Workspace::query()->where('name', 'stale-ws')->exists())->toBeFalse();
    });
});
