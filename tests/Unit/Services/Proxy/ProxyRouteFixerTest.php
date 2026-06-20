<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Ca\OrbitCaService;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Tools\CaddyTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\SiteCertificateInstallerFake;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('ProxyRouteFixer', function (): void {
    it('re-applies missing custom proxy routes from gateway intent', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('0', 64),
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $renderer = new ProxyRouteRenderer;

        $action = (new ProxyRouteFixer($shell, $renderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $caddySite = base64_decode((string) str($shell->scripts[1])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'app-1',
            'key' => 'proxy.route_missing',
            'status' => 'completed',
        ])
            ->and($shell->scripts[0])->toContain('/etc/orbit/certs/vite.docs.test.crt')
            ->and($shell->scripts[0])->toContain('/etc/orbit/certs/vite.docs.test.key')
            ->and($shell->scripts[0])->toContain(CaddyTool::reloadCommand())
            ->and($shell->scripts[1])->toContain('/etc/caddy/sites/vite.docs.test.caddy')
            ->and($caddySite)->toContain('reverse_proxy http://host.docker.internal:5173')
            ->and($caddySite)->not->toContain('127.0.0.1')
            ->and($shell->scripts[1])->toContain(CaddyTool::reloadCommand())
            ->and($shell->scripts[1])->not->toContain("docker restart 'orbit-caddy'")
            ->and($shell->scripts[1])->not->toContain('sudo systemctl reload caddy')
            ->and($route->refresh()->source_hash)->toBe(hash('sha256', $caddySite))
            ->and($route->refresh()->source_hash)->toBe($renderer->sourceHash($route));
    });

    it('repairs Orbit-managed TLS before restoring the metrics router route', function (): void {
        $router = Node::factory()->router()->create(['name' => 'gateway']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'metrics.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'owner_name' => 'grafana',
                'protocol' => 'http',
                'target' => [
                    'type' => 'upstream',
                    'value' => 'http://gateway.metrics.orbit:3000',
                ],
                'upstreams' => [
                    ['scheme' => 'http', 'host' => 'gateway.metrics.orbit', 'port' => 3000],
                ],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $renderer = new ProxyRouteRenderer;

        $action = (new ProxyRouteFixer($shell, $renderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $caddySite = base64_decode((string) str($shell->scripts[1])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'gateway',
            'key' => 'proxy.route_missing',
            'status' => 'completed',
        ])
            ->and($shell->nodes[0]->is($router))->toBeTrue()
            ->and($shell->scripts[0])->toContain('/etc/orbit/certs/metrics.orbit.crt')
            ->and($shell->scripts[0])->toContain('/etc/orbit/certs/metrics.orbit.key')
            ->and($shell->scripts[0])->toContain(base64_encode('fake-cert-for-metrics.orbit'))
            ->and($shell->scripts[0])->toContain(base64_encode('fake-key-for-metrics.orbit'))
            ->and($shell->scripts[1])->toContain('/etc/caddy/sites/metrics.orbit.caddy')
            ->and($caddySite)->toContain('tls /etc/orbit/certs/metrics.orbit.crt /etc/orbit/certs/metrics.orbit.key')
            ->and($caddySite)->toContain('reverse_proxy http://gateway.metrics.orbit:3000')
            ->and($route->refresh()->source_hash)->toBe(hash('sha256', $caddySite))
            ->and($route->refresh()->source_hash)->toBe($renderer->sourceHash($route));
    });

    it('does not issue Orbit TLS before restoring ACME-managed routes', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'www.docs.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
                'code' => 301,
                'tls' => ['managed_by' => 'acme'],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action['status'])->toBe('completed')
            ->and($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts[0])->toContain('/etc/caddy/sites/www.docs.test.caddy')
            ->and($shell->scripts[0])->not->toContain('/etc/orbit/certs/www.docs.test.crt')
            ->and($caddySite)->toContain("tls {\n        issuer acme\n    }")
            ->and($caddySite)->toContain('redir https://docs.test{uri} 301');
    });

    it('re-applies ingress routes and names the public side', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1']);
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $backend = Node::factory()->create(['name' => 'web-1']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $router->id, 'role' => 'router', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-prod', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $backend->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => ['node_id' => $router->id, 'node' => 'gateway-1', 'url' => 'http://10.6.0.2:80'],
                'router_artifact' => ['node_id' => $router->id, 'node' => 'gateway-1', 'source_hash' => str_repeat('c', 64)],
                'router_backend_pool' => [['node_id' => $backend->id, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80']],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.public_route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action['summary'])->toBe('Re-applied public proxy route docs.test from gateway intent.')
            ->and($action['node'])->toBe('edge-1')
            ->and($shell->nodes[0]->is($edge))->toBeTrue()
            ->and($shell->scripts[0])->toContain('/etc/caddy/sites/docs.test.caddy')
            ->and($caddySite)->toContain('reverse_proxy http://10.6.0.2:80')
            ->and($route->refresh()->source_hash)->toBe(hash('sha256', $caddySite));
    });

    it('re-applies router routes and names the router side', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1']);
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $backend = Node::factory()->create(['name' => 'web-1']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $router->id, 'role' => 'router', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-prod', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $backend->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => ['node_id' => $router->id, 'node' => 'gateway-1', 'url' => 'http://10.6.0.2:80'],
                'router_artifact' => ['node_id' => $router->id, 'node' => 'gateway-1', 'source_hash' => str_repeat('c', 64)],
                'router_backend_pool' => [['node_id' => $backend->id, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80']],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.router_route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action['summary'])->toBe('Re-applied private router route docs.test from gateway intent.')
            ->and($action['node'])->toBe('gateway-1')
            ->and($shell->nodes[0]->is($router))->toBeTrue()
            ->and($shell->scripts[0])->toContain('/etc/caddy/sites/docs.test.caddy')
            ->and($caddySite)->not->toContain('bind 10.6.0.2')
            ->and($caddySite)->toContain('reverse_proxy http://10.6.0.21:80')
            ->and($route->refresh()->config['router_artifact']['source_hash'])->toBe(hash('sha256', $caddySite));
    });

    it('installs the gateway CA trust pool and reloads the managed caddy container for websocket routes', function (): void {
        $router = Node::factory()->router()->create([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]);
        NodeTool::factory()->create([
            'node_id' => $router->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => ['name' => 'orbit-e2e-gateway-orbit-caddy']],
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'protocol' => 'websocket',
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    [
                        'node_id' => 42,
                        'node' => 'app-dev-1',
                        'url' => 'https://10.6.0.44:8080',
                    ],
                ],
                'router_backend_tls' => [
                    'trusted_by_gateway_ca' => true,
                    'ca_path' => '/etc/orbit/ca/root.crt',
                ],
                'tls' => [
                    'trusted_by_gateway_ca' => true,
                    'cert_path' => '/etc/orbit/certs/websocket.orbit.crt',
                    'key_path' => '/etc/orbit/certs/websocket.orbit.key',
                ],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $caddySite = base64_decode((string) str($shell->scripts[2])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action['status'])->toBe('completed')
            ->and($shell->nodes[0]->is($router))->toBeTrue()
            ->and($shell->scripts[0])->toContain('/etc/orbit/ca')
            ->and($shell->scripts[0])->toContain('/etc/orbit/ca/root.crt')
            ->and(base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true))->toBe('fake-root-ca')
            ->and($shell->nodes[1]->is($router))->toBeTrue()
            ->and($shell->scripts[1])->toContain('/etc/orbit/certs/websocket.orbit.crt')
            ->and($shell->scripts[1])->toContain('/etc/orbit/certs/websocket.orbit.key')
            ->and($shell->nodes[2]->is($router))->toBeTrue()
            ->and($shell->scripts[2])->toContain('/etc/caddy/sites/websocket.orbit.caddy')
            ->and($shell->scripts[2])->toContain(CaddyTool::reloadCommand('orbit-e2e-gateway-orbit-caddy'))
            ->and($caddySite)->toContain('tls /etc/orbit/certs/websocket.orbit.crt /etc/orbit/certs/websocket.orbit.key')
            ->and($caddySite)->toContain('reverse_proxy https://10.6.0.44:8080')
            ->and($caddySite)->toContain('tls_trust_pool file /etc/orbit/ca/root.crt')
            ->and($route->refresh()->source_hash)->toBe(hash('sha256', $caddySite));
    });

    it('re-applies private backend artifacts and names the backend side', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1']);
        $backend = Node::factory()->create(['name' => 'web-1']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-prod', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $backend->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_backend_pool' => [['node_id' => $backend->id, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80']],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'runtime_upstream' => 'http://orbit-app-docs:8080',
                    'php_socket' => null,
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.backend_route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
            detail: ['backend_node_id' => $backend->id],
        ));
        $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action['summary'])->toBe('Re-applied private backend route docs.test on web-1 from gateway intent.')
            ->and($action['node'])->toBe('web-1')
            ->and($shell->nodes[0]->is($backend))->toBeTrue()
            ->and($shell->scripts[0])->toContain('/etc/caddy/sites/docs.test.backend.caddy')
            ->and($caddySite)->not->toContain('bind 10.6.0.21')
            ->and($caddySite)->toContain('reverse_proxy http://orbit-app-docs:8080')
            ->and($caddySite)->not->toContain('php_fastcgi');
    });

    it('does not repair backend routes without an explicit matching backend node id', function (array $detail): void {
        $edge = Node::factory()->create(['name' => 'edge-1']);
        $backend = Node::factory()->create(['name' => 'web-1']);
        $otherBackend = Node::factory()->create(['name' => 'web-2']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-prod', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $otherBackend->id, 'role' => 'app-prod', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $backend->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_backend_pool' => [['node_id' => $backend->id, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80']],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.backend_route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
            detail: $detail,
        ));

        expect($action)->toBeNull()
            ->and($shell->scripts)->toBe([])
            ->and($shell->nodes)->toBe([]);
    })->with([
        'missing backend node id' => [[]],
        'invalid backend node id' => [['backend_node_id' => 'web-1']],
        'nonmatching backend node id' => [['backend_node_id' => 999_999]],
    ]);

    it('repairs missing Orbit-managed TLS material for custom proxy routes', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.tls_missing',
            kind: DriftKind::Missing,
            summary: 'tls missing',
        ));

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'app-1',
            'key' => 'proxy.tls_missing',
            'status' => 'completed',
        ])
            ->and($shell->scripts[0])->toContain('/etc/orbit/certs/vite.docs.test.crt')
            ->and($shell->scripts[0])->toContain('/etc/orbit/certs/vite.docs.test.key')
            ->and($shell->scripts[0])->toContain("sudo chmod 0644 '/etc/orbit/certs/vite.docs.test.crt'")
            ->and($shell->scripts[0])->toContain("sudo chmod 0600 '/etc/orbit/certs/vite.docs.test.key'")
            ->and($shell->scripts[0])->not->toContain('systemctl show caddy')
            ->and($shell->scripts[0])->not->toContain('orbit_caddy_group')
            ->and($shell->scripts[0])->not->toContain('getent group caddy')
            ->and($shell->scripts[0])->not->toContain('chgrp')
            ->and($shell->scripts[0])->toContain(CaddyTool::reloadCommand())
            ->and($shell->scripts[0])->not->toContain("docker restart 'orbit-caddy'")
            ->and($shell->scripts[0])->not->toContain('sudo systemctl reload caddy');
    });

    it('re-applies app proxy routes from gateway intent', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'document_root' => '/home/orbit/apps/docs/public',
                'runtime_upstream' => 'http://orbit-app-docs:8080',
                'php_socket' => null,
                'tls' => 'internal',
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $certificates = new SiteCertificateInstallerFake;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, $certificates))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
        ));
        $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'app-1',
            'key' => 'proxy.route_mismatch',
            'status' => 'completed',
        ])
            ->and($shell->scripts[0])->toContain('/etc/caddy/sites/docs.test.caddy')
            ->and($caddySite)->toContain('tls /etc/orbit/certs/docs.test.crt /etc/orbit/certs/docs.test.key')
            ->and($caddySite)->toContain('reverse_proxy http://orbit-app-docs:8080')
            ->and($caddySite)->not->toContain('php_fastcgi')
            ->and($certificates->hosts)->toBe(['docs.test'])
            ->and($route->refresh()->source_hash)->toBe(hash('sha256', $caddySite))
            ->and($route->refresh()->source_hash)->toBe((new ProxyRouteRenderer)->sourceHash($route));
    });

    it('repairs app route TLS through the site certificate installer', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'document_root' => '/home/orbit/apps/docs/public',
                'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                'tls' => [
                    'cert_path' => '/home/orbit/.config/orbit/certs/docs.test.crt',
                    'key_path' => '/home/orbit/.config/orbit/certs/docs.test.key',
                ],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $certificates = new SiteCertificateInstallerFake;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, $certificates))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.tls_missing',
            kind: DriftKind::Missing,
            summary: 'tls missing',
        ));

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'app-1',
            'key' => 'proxy.tls_missing',
            'status' => 'completed',
        ])
            ->and($certificates->hosts)->toBe(['docs.test'])
            ->and($shell->scripts)->toBe([]);
    });

    it('restores a legacy app route persisted with only php_socket by deriving the FrankenPHP runtime upstream from the app identity (instead of throwing)', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->for($node, 'node')->create(['name' => 'legacy-docs']);
        $route = ProxyRoute::factory()
            ->for($node, 'node')
            ->for($app, 'app')
            ->create([
                'domain' => 'legacy-docs.test',
                'owner_type' => 'app',
                'kind' => 'app',
                'source_hash' => str_repeat('0', 64),
                'config' => [
                    'document_root' => '/home/orbit/apps/legacy-docs/public',
                    // origin/main legacy persisted config: only php_socket, no runtime_upstream
                    'php_socket' => '/var/run/php/orbit-legacy-docs.sock',
                    'tls' => [
                        'cert_path' => '/etc/orbit/certs/legacy-docs.test.crt',
                        'key_path' => '/etc/orbit/certs/legacy-docs.test.key',
                    ],
                ],
            ]);

        $shell = new ProxyFixerRecordingRemoteShell;
        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
        ));

        $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action['status'])->toBe('completed')
            ->and($caddySite)->toContain('reverse_proxy http://orbit-app-legacy-docs:8080')
            ->and($caddySite)->not->toContain('php_fastcgi')
            ->and($caddySite)->not->toContain('file_server');
    });

    it('starts the orbit-caddy container on the serving node when proxy.caddy_container_down is reported', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fixCaddyContainer(
            $node,
            new DriftEntry(
                family: 'proxy',
                key: 'proxy.caddy_container_down',
                kind: DriftKind::Divergent,
                summary: 'orbit-caddy is not running',
                detail: ['container' => 'orbit-caddy', 'node' => 'app-1'],
            ),
        );

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'app-1',
            'key' => 'proxy.caddy_container_down',
            'status' => 'completed',
        ])
            ->and($shell->nodes[0]->is($node))->toBeTrue()
            ->and($shell->scripts[0])->toContain("docker start 'orbit-caddy'")
            ->and($shell->scripts[0])->not->toContain('systemctl')
            ->and($shell->scripts[0])->not->toContain('caddy.service');
    });

    it('reconciles the orbit-caddy container on the serving node using the per-node managed spec when proxy.caddy_container_missing is reported', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1', 'wireguard_address' => '10.6.0.21']);
        // Persist a role-specific spec on the NodeTool record so the fixer
        // recreates orbit-caddy with the per-node bindings (private node) and
        // not the generic default spec.
        $managedSpec = OrbitCaddyContainer::forPrivateNode('10.6.0.21')->spec();
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => $managedSpec],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fixCaddyContainer(
            $node,
            new DriftEntry(
                family: 'proxy',
                key: 'proxy.caddy_container_missing',
                kind: DriftKind::Missing,
                summary: 'orbit-caddy is absent',
                detail: ['container' => 'orbit-caddy', 'node' => 'app-1'],
            ),
        );

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'app-1',
            'key' => 'proxy.caddy_container_missing',
            'status' => 'completed',
        ])
            ->and($shell->nodes[0]->is($node))->toBeTrue()
            ->and($shell->scripts[0])->toContain('orbit-caddy')
            ->and($shell->scripts[0])->toContain('docker run')
            // Per-node spec includes WireGuard-bound port publish; the default
            // spec does not. This proves the fixer used the managed spec.
            ->and($shell->scripts[0])->toContain('10.6.0.21:80:80')
            ->and($shell->scripts[0])->not->toContain('systemctl');
    });

    it('refuses to recreate orbit-caddy when the node has no managed caddy tool record', function (): void {
        $node = createTestAppHostNode(['name' => 'app-2']);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fixCaddyContainer(
            $node,
            new DriftEntry(
                family: 'proxy',
                key: 'proxy.caddy_container_missing',
                kind: DriftKind::Missing,
                summary: 'orbit-caddy is absent',
                detail: ['container' => 'orbit-caddy', 'node' => 'app-2'],
            ),
        );

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'app-2',
            'key' => 'proxy.caddy_container_missing',
            'status' => 'refused',
        ])
            ->and($action['details']['reason'])->toBe('no_managed_caddy_tool')
            ->and($shell->scripts)->toBe([])
            ->and($shell->nodes)->toBe([]);
    });

    it('uses the public-ingress spec when the node is an ingress role host', function (): void {
        $node = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.4']);
        NodeRoleAssignment::factory()->create(['node_id' => $node->id, 'role' => 'ingress', 'status' => 'active']);
        // Spec containing public-ingress port bindings (80/443/443 udp +
        // wireguard backend port). These do not appear in the default spec.
        $managedSpec = OrbitCaddyContainer::forPublicIngress('10.6.0.4')->spec();
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'config' => ['container' => $managedSpec],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fixCaddyContainer(
            $node,
            new DriftEntry(
                family: 'proxy',
                key: 'proxy.caddy_container_missing',
                kind: DriftKind::Missing,
                summary: 'orbit-caddy is absent',
                detail: ['container' => 'orbit-caddy', 'node' => 'edge-1'],
            ),
        );

        expect($action['status'])->toBe('completed')
            ->and($shell->scripts[0])->toContain('80:80')
            ->and($shell->scripts[0])->toContain('443:443')
            ->and($shell->scripts[0])->toContain('10.6.0.4:'.OrbitCaddyContainer::PrivateBackendPort.':'.OrbitCaddyContainer::PrivateBackendPort);
    });

    it('restores a legacy private backend artifact persisted with only php_socket by deriving runtime_upstream from the app identity', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1']);
        $backend = Node::factory()->create(['name' => 'web-1']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-prod', 'status' => 'active']);
        $app = App::factory()->for($backend, 'node')->create(['name' => 'legacy-docs']);
        $route = ProxyRoute::factory()
            ->for($edge, 'node')
            ->for($app, 'app')
            ->create([
                'domain' => 'legacy-docs.test',
                'owner_type' => 'app',
                'kind' => 'app',
                'source_hash' => str_repeat('0', 64),
                'config' => [
                    'placement' => 'ingress',
                    'backend_artifacts' => [
                        [
                            'node_id' => $backend->id,
                            'bind' => '10.6.0.21',
                            'document_root' => '/home/orbit/apps/legacy-docs/public',
                            // legacy backend artifact: php_socket only, no runtime_upstream
                            'php_socket' => '/var/run/php/orbit-legacy-docs.sock',
                        ],
                    ],
                ],
            ]);

        $shell = new ProxyFixerRecordingRemoteShell;
        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.backend_route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'backend mismatch',
            detail: ['backend_node_id' => $backend->id],
        ));

        $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action['status'])->toBe('completed')
            ->and($caddySite)->toContain('reverse_proxy http://orbit-app-legacy-docs:8080')
            ->and($caddySite)->not->toContain('php_fastcgi');
    });
});

final readonly class ProxyFixerFakeCa extends OrbitCaService
{
    public function rootCert(): string
    {
        return 'fake-root-ca';
    }

    /** @return array{cert: string, key: string} */
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $dir = sys_get_temp_dir().'/orbit-proxy-fixer-ca';

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $cert = "{$dir}/{$host}.crt";
        $key = "{$dir}/{$host}.key";

        file_put_contents($cert, "fake-cert-for-{$host}");
        file_put_contents($key, "fake-key-for-{$host}");

        return ['cert' => $cert, 'key' => $key];
    }
}

final class ProxyFixerRecordingRemoteShell implements RemoteShell
{
    /** @var list<Node> */
    public array $nodes = [];

    /** @var list<string> */
    public array $scripts = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
