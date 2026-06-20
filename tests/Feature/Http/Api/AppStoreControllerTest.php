<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

const APP_STORE_CALLER_WG_IP = '10.6.0.77';

function createAppStoreCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_STORE_CALLER_WG_IP,
        'wireguard_address' => APP_STORE_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantAppStoreAccess(Node $caller, Node $appNode, array $permissions = ['app:new']): void
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

function assignAppStoreRole(Node $node, string $role, string $status = 'active', array $settings = []): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
    ]);
}

describe('AppStoreController', function (): void {
    it('creates app source and registry intent for authorized callers', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
            'root' => 'public',
            'php_version' => '8.4',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.result.action', 'created')
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.app.node', 'app-1')
            ->assertJsonPath('success.data.app.php_version', '8.4')
            ->assertJsonPath('success.data.app.runtime_kind', 'php')
            ->assertJsonPath('success.data.app.worker_enabled', false)
            ->assertJsonPath('success.data.app.worker_config', null)
            ->assertJsonPath('success.meta.warnings', []);

        expect(App::query()->where('name', 'docs')->exists())->toBeTrue()
            ->and($remoteShell->runs)->toHaveCount(8);
    });

    it('rejects app creation when the caller lacks app:new on the target app node', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode, ['app:read']);

        $remoteShell = new AppStoreRecordingRemoteShell(scriptResults: [
            "id -u 'docs'" => new RemoteShellResult(
                exitCode: 0,
                stdout: "1001\n1002\n",
                stderr: '',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:new')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(App::query()->count())->toBe(0)
            ->and($remoteShell->runs)->toBe([]);
    });

    it('allows database-role callers when app:new is granted on the target app node', function (): void {
        $caller = createAppStoreCallerNode();
        assignAppStoreRole($caller, 'database');
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
            'root' => 'public',
            'php_version' => '8.5',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.result.action', 'created')
            ->assertJsonPath('success.data.app.name', 'docs');

        expect(App::query()->where('name', 'docs')->exists())->toBeTrue()
            ->and($remoteShell->runs)->not->toBe([]);
    });

    it('rejects app creation before remote work when the proxy route domain is already registered', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        ProxyRoute::query()->create([
            'node_id' => $targetNode->id,
            'domain' => 'docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
        ]);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertConflict()
            ->assertJsonPath('error.code', 'proxy.domain_conflict')
            ->assertJsonPath('error.meta.domain', 'docs.test');

        expect(App::query()->count())->toBe(0)
            ->and($remoteShell->runs)->toBe([]);
    });

    it('reports github transport when github source creation fails', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        assignAppStoreRole($targetNode, 'app-dev', settings: ['tld' => 'test']);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell(new RemoteShellResult(
            exitCode: 128,
            stdout: '',
            stderr: "permission denied\n",
            durationMs: 5,
        ));
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
            'repository' => 'hardimpact/docs',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertServerError()
            ->assertJsonPath('error.code', 'app.source_creation_failed')
            ->assertJsonPath('error.meta.reason', 'permission denied')
            ->assertJsonPath('error.meta.transport', 'github');

        expect(App::query()->where('name', 'docs')->exists())->toBeFalse()
            ->and($remoteShell->runs[0]['script'])->toContain("gh repo clone 'hardimpact/docs'");
    });

    it('creates production app routes on ingress and backend app nodes', function (): void {
        $caller = createAppStoreCallerNode();
        $router = Node::factory()->create([
            'name' => 'gateway-1',
            'status' => 'active',
            'wireguard_address' => '10.6.0.2',
        ]);
        assignAppStoreRole($router, 'router');
        $ingress = Node::factory()->create([
            'name' => 'edge-1',
            'status' => 'active',
        ]);
        assignAppStoreRole($ingress, 'ingress');

        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
            'wireguard_address' => '10.6.0.21',
            'user' => 'orbit',
        ]);
        assignAppStoreRole($targetNode, 'app-prod', settings: ['ingress_node_id' => $ingress->id]);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell(scriptResults: [
            "id -u 'docs'" => new RemoteShellResult(
                exitCode: 0,
                stdout: "1001\n1002\n",
                stderr: '',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
            'domain' => 'docs.example.com',
            'root' => 'public',
            'php_version' => '8.5',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.app.url', 'https://docs.example.com')
            ->assertJsonPath('success.meta.warnings.0.code', 'proxy.domain_inactive');

        expect(App::query()->where('name', 'docs')->value('environment'))->toBe('production');

        $route = ProxyRoute::query()->where('domain', 'docs.example.com')->firstOrFail();

        expect($route->node_id)->toBe($ingress->id)
            ->and($route->config['placement'])->toBe('ingress')
            ->and($route->config['ingress_node_id'])->toBe($ingress->id)
            ->and($route->config['router_upstream'])->toBe([
                'node_id' => $router->id,
                'node' => 'gateway-1',
                'url' => 'http://10.6.0.2:80',
            ])
            ->and($route->config['router_artifact']['node_id'])->toBe($router->id)
            ->and($route->config['router_artifact']['source_hash'])->toHaveLength(64)
            ->and($route->config['router_backend_pool'])->toBe([
                [
                    'node_id' => $targetNode->id,
                    'node' => 'app-1',
                    'url' => 'http://10.6.0.21:8081',
                ],
            ])
            ->and($route->config['backend_artifacts'][0]['node_id'])->toBe($targetNode->id)
            ->and($route->config['backend_artifacts'][0]['bind'])->toBe('10.6.0.21')
            ->and($route->config['backend_artifacts'][0]['document_root'])->toBe('/home/docs/app/public')
            ->and($route->config['backend_artifacts'][0]['runtime_upstream'])->toBe('http://orbit-app-docs:8080')
            ->and($route->config['backend_artifacts'][0]['php_socket'])->toBeNull()
            ->and($route->config['backend_artifacts'][0]['source_hash'])->toHaveLength(64)
            ->and($route->source_hash)->toHaveLength(64)
            ->and(collect($remoteShell->runs)->pluck('node')->all())->toContain($ingress->id, $router->id, $targetNode->id)
            ->and(collect($remoteShell->runs)->contains(fn (array $run): bool => $run['node'] === $ingress->id && str_contains($run['script'], 'sudo test -f /etc/caddy/Caddyfile')))->toBeTrue()
            ->and(collect($remoteShell->runs)->contains(fn (array $run): bool => $run['node'] === $ingress->id && str_contains($run['script'], 'sudo install -d -m 0755 /etc/caddy')))->toBeTrue()
            ->and(collect($remoteShell->runs)->contains(fn (array $run): bool => $run['node'] === $ingress->id && str_contains($run['script'], 'sudo tee /etc/caddy/Caddyfile >/dev/null')))->toBeTrue()
            ->and(collect($remoteShell->runs)->contains(fn (array $run): bool => $run['node'] === $router->id && str_contains($run['script'], 'sudo test -f /etc/caddy/Caddyfile')))->toBeTrue()
            ->and(collect($remoteShell->runs)->contains(fn (array $run): bool => $run['node'] === $router->id && str_contains($run['script'], 'sudo install -d -m 0755 /etc/caddy')))->toBeTrue()
            ->and(collect($remoteShell->runs)->contains(fn (array $run): bool => $run['node'] === $router->id && str_contains($run['script'], 'sudo tee /etc/caddy/Caddyfile >/dev/null')))->toBeTrue()
            ->and(collect($remoteShell->runs)->contains(fn (array $run): bool => $run['node'] === $targetNode->id && str_contains($run['script'], 'sudo test -f /etc/caddy/Caddyfile')))->toBeTrue()
            ->and(collect($remoteShell->runs)->contains(fn (array $run): bool => $run['node'] === $targetNode->id && str_contains($run['script'], 'sudo install -d -m 0755 /etc/caddy')))->toBeTrue()
            ->and(collect($remoteShell->runs)->contains(fn (array $run): bool => $run['node'] === $targetNode->id && str_contains($run['script'], 'sudo tee /etc/caddy/Caddyfile >/dev/null')))->toBeTrue()
            ->and(collect($remoteShell->runs)->pluck('script')->contains(fn (string $script): bool => str_contains($script, '/etc/caddy/sites/docs.example.com.caddy')))->toBeTrue()
            ->and(collect($remoteShell->runs)->pluck('script')->contains(fn (string $script): bool => str_contains($script, '/etc/caddy/sites/docs.example.com.backend.caddy')))->toBeTrue();
    });
});

final class AppStoreRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<array{node: int|null, script: string, options: array<string, mixed>}>
     */
    public array $runs = [];

    /**
     * @param  array<string, RemoteShellResult>  $scriptResults
     */
    public function __construct(
        private readonly ?RemoteShellResult $result = null,
        private readonly array $scriptResults = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = [
            'node' => $node->id,
            'script' => $script,
            'options' => $options,
        ];

        foreach ($this->scriptResults as $needle => $result) {
            if (str_contains($script, $needle)) {
                return $result;
            }
        }

        return $this->result ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
