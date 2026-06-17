<?php

declare(strict_types=1);

use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROCESS_LIST_CALLER_WG_IP = '10.6.0.88';

function createProcessListCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROCESS_LIST_CALLER_WG_IP,
        'wireguard_address' => PROCESS_LIST_CALLER_WG_IP], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantProcessListAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['process:read'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now()]);
}

describe('ProcessListController', function (): void {
    it('lists app processes in process order with runtime units', function (): void {
        $caller = createProcessListCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        grantProcessListAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);

        Process::factory()->forOwner($app)->create([
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'restart_policy' => ProcessRestartPolicy::Always,
            'crash_notification' => ProcessCrashNotification::None,
            'sort_order' => 20]);
        Process::factory()->forOwner($app)->create([
            'name' => 'vite',
            'command' => 'npm run dev',
            'restart_policy' => ProcessRestartPolicy::Never,
            'crash_notification' => ProcessCrashNotification::AgentIde,
            'sort_order' => 10]);

        $response = $this->call('GET', '/api/processes?app=docs', [], [], [], ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.context', ['node' => 'app-1', 'app' => 'docs', 'workspace' => null])
            ->assertJsonPath('success.data.processes.0.name', 'vite')
            ->assertJsonPath('success.data.processes.0.runtime_unit', 'orbit_docs_main_vite')
            ->assertJsonPath('success.data.processes.0.last_event', null)
            ->assertJsonPath('success.data.processes.1.name', 'queue');
    });

    it('uses workspace context for inherited process runtime units', function (): void {
        createProcessListCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'sort_order' => 1]);

        $response = $this->call('GET', '/api/processes?app=docs&workspace=feature-docs', [], [], [], ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.context', ['node' => 'app-1', 'app' => 'docs', 'workspace' => 'feature-docs'])
            ->assertJsonPath('success.data.processes.0.runtime_unit', 'orbit_docs_feature-docs_vite');
    });

    it('lists workspace owned process rows for workspace context', function (): void {
        createProcessListCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()->forOwner($workspace)->create([
            'name' => 'frankenphp-docs-feature-docs',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => ['container_name' => 'orbit-ws-docs-feature-docs'],
            'sort_order' => 1,
        ]);

        $response = $this->call('GET', '/api/processes?app=docs&workspace=feature-docs', [], [], [], ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.context', ['node' => 'app-1', 'app' => 'docs', 'workspace' => 'feature-docs'])
            ->assertJsonPath('success.data.processes.0.name', 'frankenphp-docs-feature-docs')
            ->assertJsonPath('success.data.processes.0.runtime_unit', 'orbit-ws-docs-feature-docs');
    });

    it('lists node owned process rows for node context', function (): void {
        createProcessListCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'app-1']);
        Process::factory()->forOwner($node)->create([
            'name' => 'opencode-server',
            'runtime' => ProcessRuntime::Systemd,
            'tool' => 'opencode',
            'sort_order' => 1,
        ]);

        $response = $this->call('GET', '/api/processes?node=app-1', [], [], [], ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.context', ['node' => 'app-1', 'app' => null, 'workspace' => null])
            ->assertJsonPath('success.data.processes.0.name', 'opencode-server')
            ->assertJsonPath('success.data.processes.0.tool', 'opencode')
            ->assertJsonPath('success.data.processes.0.runtime', 'systemd')
            ->assertJsonPath('success.data.processes.0.runtime_unit', 'opencode-server');
    });

    it('lists service definition connection metadata for node owned service processes without exposing credential values', function (): void {
        createProcessListCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        Process::factory()->forOwner($node)->create([
            'name' => 'mysql8',
            'command' => 'mysqld',
            'runtime' => ProcessRuntime::DockerSwarm,
            'runtime_config' => [
                'definition' => 'mysql',
                'version_family' => '8',
                'version' => '8.4',
                'service_name' => 'orbit-mysql8',
                'endpoint' => [
                    'name' => 'mysql8',
                    'kind' => 'tcp',
                    'host' => '10.6.0.44',
                    'port' => 3308,
                ],
                'credentials' => [
                    'database' => 'orbit',
                    'password' => 'orbit',
                    'username' => 'orbit',
                ],
            ],
            'sort_order' => 1,
        ]);

        $response = $this->call('GET', '/api/processes?node=database-1', [], [], [], ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.processes.0.name', 'mysql8')
            ->assertJsonPath('success.data.processes.0.tool', null)
            ->assertJsonPath('success.data.processes.0.runtime', 'docker-swarm')
            ->assertJsonPath('success.data.processes.0.runtime_unit', 'orbit-mysql8')
            ->assertJsonPath('success.data.processes.0.service.definition', 'mysql')
            ->assertJsonPath('success.data.processes.0.service.version_family', '8')
            ->assertJsonPath('success.data.processes.0.service.version', '8.4')
            ->assertJsonPath('success.data.processes.0.service.endpoint.host', '10.6.0.44')
            ->assertJsonPath('success.data.processes.0.service.endpoint.port', 3308)
            ->assertJsonPath('success.data.processes.0.service.credential_fields', ['database', 'password', 'username'])
            ->assertJsonMissingPath('success.data.processes.0.service.credentials');
    });

    it('omits process intent hidden from the caller', function (): void {
        $caller = createProcessListCallerNode();
        $visibleNode = createTestAppHostNode();
        $hiddenNode = createTestAppHostNode();
        grantProcessListAccess($caller, $visibleNode);

        App::factory()->create(['name' => 'visible', 'node_id' => $visibleNode->id]);
        $hiddenApp = App::factory()->create(['name' => 'hidden', 'node_id' => $hiddenNode->id]);
        Process::factory()->forOwner($hiddenApp)->create(['name' => 'queue']);

        $response = $this->call('GET', '/api/processes?app=hidden', [], [], [], ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'app');
    });

    it('returns authorization failure when the caller has no process visibility', function (): void {
        createProcessListCallerNode();
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create();

        $response = $this->call('GET', '/api/processes?app=docs', [], [], [], ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:read');
    });

    it('returns validation errors for missing and unknown contexts', function (string $query, string $field): void {
        createProcessListCallerNode(role: 'gateway');
        createTestAppHostNode();

        $response = $this->call('GET', "/api/processes{$query}", [], [], [], ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field);
    })->with([
        'missing app' => ['', 'app'],
        'unknown app' => ['?app=missing', 'app'],
        'unknown workspace' => ['?workspace=missing', 'workspace']]);

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/processes?app=docs');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
