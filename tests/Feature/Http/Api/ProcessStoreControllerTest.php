<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
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

const PROCESS_STORE_CALLER_WG_IP = '10.6.0.89';

function createProcessStoreCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROCESS_STORE_CALLER_WG_IP,
        'wireguard_address' => PROCESS_STORE_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantProcessStoreAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['process:add'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProcessStoreController', function (): void {
    it('creates process intent for authorized control callers', function (): void {
        $caller = createProcessStoreCallerNode();
        $appNode = createTestAppHostNode();
        grantProcessStoreAccess($caller, $appNode);
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'vite',
            'command' => 'npm run dev',
            'restart_policy' => 'on_failure',
            'crash_notification' => 'agent_ide',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process.name', 'vite')
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.runtime_units.0.name', 'orbit_docs_main_vite')
            ->assertJsonPath('success.meta.warnings', []);

        expect(Process::query()->where('name', 'vite')->value('runtime'))->toBe(ProcessRuntime::Systemd);
    });

    it('defaults workspace command processes to systemd for PHP app workspaces', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        $workspace = Workspace::factory()->for($app)->create(['name' => 'feature-docs']);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'name' => 'horizon',
            'command' => 'php artisan horizon',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process.name', 'horizon')
            ->assertJsonPath('success.data.process.workspace', 'feature-docs')
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.runtime_units.0.name', 'orbit_docs_feature-docs_horizon');

        $process = Process::query()->where('name', 'horizon')->firstOrFail();

        expect($process->owner_type)->toBe($workspace->getMorphClass())
            ->and($process->owner_id)->toBe($workspace->id)
            ->and($process->runtime)->toBe(ProcessRuntime::Systemd);
    });

    it('rejects unauthorized callers before writing intent', function (): void {
        createProcessStoreCallerNode();
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'vite',
            'command' => 'npm run dev',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:add');

        expect(Process::query()->exists())->toBeFalse();
    });

    it('denies app callers without a process add grant before writing intent', function (): void {
        $caller = createProcessStoreCallerNode(role: 'app-dev');
        App::factory()->create(['name' => 'docs', 'node_id' => $caller->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'vite',
            'command' => 'npm run dev',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:add');
    });

    it('returns validation errors before writing intent', function (array $payload, string $field): void {
        createProcessStoreCallerNode(role: 'gateway');
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call('POST', '/api/processes', $payload, [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field);

        expect(Process::query()->exists())->toBeFalse();
    })->with([
        'missing app' => [['name' => 'vite', 'command' => 'npm run dev'], 'app'],
        'missing name' => [['app' => 'docs', 'command' => 'npm run dev'], 'name'],
        'missing command' => [['app' => 'docs', 'name' => 'vite'], 'command'],
        'invalid restart' => [['app' => 'docs', 'name' => 'vite', 'command' => 'npm run dev', 'restart_policy' => 'sometimes'], 'restart_policy'],
    ]);

    it('persists and returns an explicit systemd runtime when supplied', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'legacy',
            'command' => './legacy.sh',
            'runtime' => 'systemd',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process.runtime', 'systemd');

        expect(Process::query()->where('name', 'legacy')->value('runtime')->value)->toBe('systemd');
    });

    it('rejects supervisor runtime values before writing intent', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'legacy',
            'command' => './legacy.sh',
            'runtime' => 'supervisor',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'supervisor')
            ->assertJsonPath('error.meta.allowed', ['docker', 'docker-swarm', 'systemd']);

        expect(Process::query()->where('name', 'legacy')->exists())->toBeFalse();
    });

    it('rejects invalid runtime values with the documented validation envelope', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'runtime' => 'podman',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'podman')
            ->assertJsonPath('error.meta.allowed', ['docker', 'docker-swarm', 'systemd']);

        expect(Process::query()->where('name', 'queue')->exists())->toBeFalse();
    });

    it('rejects docker swarm for app scoped process creation before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'mysql8',
            'command' => 'mysqld',
            'runtime' => 'docker-swarm',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker-swarm')
            ->assertJsonPath('error.meta.reason', 'docker_swarm_requires_node_owned_process');

        expect(Process::query()->where('name', 'mysql8')->exists())->toBeFalse()
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects docker for app scoped host-command process creation before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'runtime' => 'docker',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker')
            ->assertJsonPath('error.meta.reason', 'docker_runtime_requires_service_or_managed_process');

        expect(Process::query()->where('name', 'queue')->exists())->toBeFalse()
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('rejects docker for workspace scoped host-command process creation before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Workspace::factory()->for($app)->create(['name' => 'feature-docs']);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'workspace' => 'feature-docs',
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'runtime' => 'docker',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'runtime')
            ->assertJsonPath('error.meta.value', 'docker')
            ->assertJsonPath('error.meta.reason', 'docker_runtime_requires_service_or_managed_process');

        expect(Process::query()->where('name', 'queue')->exists())->toBeFalse()
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('creates node owned systemd process intent with an optional tool dependency', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'app-1']);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/processes', [
            'node' => 'app-1',
            'name' => 'opencode-server',
            'command' => 'opencode serve --hostname 0.0.0.0',
            'runtime' => 'systemd',
            'tool' => 'opencode',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process.name', 'opencode-server')
            ->assertJsonPath('success.data.process.node', 'app-1')
            ->assertJsonPath('success.data.process.tool', 'opencode')
            ->assertJsonPath('success.data.process.runtime', 'systemd')
            ->assertJsonPath('success.data.runtime_units.0.name', 'opencode-server');

        $process = Process::query()->where('name', 'opencode-server')->firstOrFail();

        expect($process->owner_type)->toBe($node->getMorphClass())
            ->and($process->owner_id)->toBe($node->id)
            ->and($process->node_id)->toBe($node->id)
            ->and($process->tool)->toBe('opencode')
            ->and($process->runtime)->toBe(ProcessRuntime::Systemd)
            ->and($remoteShell->scripts[0])->toContain("sudo systemctl enable 'opencode-server.service'");
    });

    it('creates node owned MySQL service processes from process definitions without tool rows', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        $remoteShell = new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/processes', [
            'node' => 'database-1',
            'name' => 'mysql8',
            'definition' => 'mysql',
            'version' => '8',
            'runtime' => 'docker-swarm',
            'restart_policy' => 'on_failure',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process.name', 'mysql8')
            ->assertJsonPath('success.data.process.node', 'database-1')
            ->assertJsonPath('success.data.process.tool', null)
            ->assertJsonPath('success.data.process.runtime', 'docker-swarm')
            ->assertJsonPath('success.data.runtime_units.0.name', 'orbit-mysql8');

        $process = Process::query()->where('name', 'mysql8')->firstOrFail();

        expect($process->owner_type)->toBe($node->getMorphClass())
            ->and($process->owner_id)->toBe($node->id)
            ->and($process->tool)->toBeNull()
            ->and($process->runtime)->toBe(ProcessRuntime::DockerSwarm)
            ->and($process->runtime_config)->toMatchArray([
                'definition' => 'mysql',
                'version_family' => '8',
                'version' => '8.4',
            ])
            ->and($process->runtime_config['endpoint']['host'])->toBe('10.6.0.44')
            ->and($process->runtime_config['endpoint']['port'])->toBe(3308)
            ->and($process->runtime_config['labels']['orbit.process.definition'])->toBe('mysql')
            ->and($process->runtime_config['labels']['orbit.process.version_family'])->toBe('8')
            ->and($remoteShell->scripts[0])->toContain('docker service create')
            ->and($remoteShell->scripts[0])->toContain("--label 'orbit.process.definition=mysql'");
    });

    it('lets MySQL 8 and MySQL 9 process definitions coexist on one node', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        foreach ([['mysql8', '8'], ['mysql9', '9']] as [$name, $version]) {
            $this->call('POST', '/api/processes', [
                'node' => 'database-1',
                'name' => $name,
                'definition' => 'mysql',
                'version' => $version,
                'runtime' => 'docker',
            ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP])->assertOk();
        }

        $mysql8 = Process::query()->where('name', 'mysql8')->firstOrFail();
        $mysql9 = Process::query()->where('name', 'mysql9')->firstOrFail();

        expect($mysql8->owner_id)->toBe($node->id)
            ->and($mysql9->owner_id)->toBe($node->id)
            ->and($mysql8->runtime_config['endpoint']['port'])->toBe(3308)
            ->and($mysql9->runtime_config['endpoint']['port'])->toBe(3309)
            ->and($mysql8->runtime_config['spec_hash'])->not->toBe($mysql9->runtime_config['spec_hash']);
    });

    it('rejects invalid service definition input before runtime side effects', function (array $payload, string $field, string $reason): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'database-1']);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/processes', $payload, [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field)
            ->assertJsonPath('error.meta.reason', $reason);

        expect(Process::query()->whereIn('name', ['redis', 'mysql8'])->exists())->toBeFalse()
            ->and($remoteShell->scripts)->toBe([]);
    })->with([
        'app owner' => [
            [
                'app' => 'docs',
                'name' => 'redis',
                'definition' => 'redis',
                'version' => '7',
                'runtime' => 'docker',
            ],
            'definition',
            'process_definition_requires_node_owned_process',
        ],
        'tool dependency' => [
            [
                'node' => 'database-1',
                'name' => 'redis',
                'definition' => 'redis',
                'version' => '7',
                'runtime' => 'docker',
                'tool' => 'redis',
            ],
            'tool',
            'process_definition_cannot_reference_tool',
        ],
        'version without definition' => [
            [
                'node' => 'database-1',
                'name' => 'worker',
                'command' => 'php artisan queue:work',
                'version' => '8',
                'runtime' => 'docker',
            ],
            'version',
            'process_definition_version_requires_definition',
        ],
        'service definition node without WireGuard address' => [
            [
                'node' => 'database-1',
                'name' => 'redis',
                'definition' => 'redis',
                'version' => '7',
                'runtime' => 'docker',
            ],
            'node',
            'wireguard_address_required',
        ],
    ]);

    it('rejects service definition endpoint conflicts before runtime side effects', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        Process::factory()->forOwner($node)->create([
            'name' => 'existing-redis',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'endpoints' => [
                    ['name' => 'existing-redis', 'kind' => 'tcp', 'host' => '10.6.0.44', 'port' => 6379],
                ],
            ],
        ]);
        $remoteShell = new ProcessStoreRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/processes', [
            'node' => 'database-1',
            'name' => 'redis',
            'definition' => 'redis',
            'version' => '7',
            'runtime' => 'docker',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'endpoint_conflict')
            ->assertJsonPath('error.meta.existing_process', 'existing-redis')
            ->assertJsonPath('error.meta.port', 6379);

        expect(Process::query()->where('name', 'redis')->exists())->toBeFalse()
            ->and($remoteShell->scripts)->toBe([]);
    });

    it('returns duplicate process conflicts', function (): void {
        createProcessStoreCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'vite',
            'command' => 'npm run dev',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'process.name_collision');
    });
});

final class ProcessStoreRemoteShell implements RemoteShell
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
