<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\S3\S3RouteRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @group service
 */
function s3AssignRole(Node $node, string $role, string $status = 'active', array $settings = []): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => $status,
        'settings' => $settings,
    ]);
}

it('registers router-owned s3 service route to one seaweedfs backend', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'gateway');
    s3AssignRole($router, 'vpn');
    s3AssignRole($router, 'router');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => [],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncServiceRoute();

    $route = ProxyRoute::query()->where('domain', 's3.orbit')->firstOrFail();

    expect($route)
        ->node_id->toBe($router->id)
        ->owner_type->toBe('router')
        ->kind->toBe('proxy')
        ->and($route->config)->toMatchArray([
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'http://storage-1.s3.orbit:8333'],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'storage-1.s3.orbit', 'port' => 8333],
            ],
        ]);
})->group('service');

it('stores the pool shape even for a single seaweedfs backend', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => [],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncServiceRoute();

    $route = ProxyRoute::query()->where('domain', 's3.orbit')->firstOrFail();

    expect($route->config['upstreams'])->toHaveCount(1)
        ->and($route->config['upstreams'][0])->toBe([
            'scheme' => 'http',
            'host' => 'storage-1.s3.orbit',
            'port' => 8333,
        ]);
})->group('service');

it('fails clearly when there is no active router node', function (): void {
    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
    ]);

    app(S3RouteRegistrar::class)->syncServiceRoute();
})->throws(RuntimeException::class, 'The S3 service route requires an active router node.')
    ->group('service');

it('fails clearly when there are no active s3 nodes', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    app(S3RouteRegistrar::class)->syncServiceRoute();
})->throws(RuntimeException::class, 'The S3 service route requires at least one active s3 backend.')
    ->group('service');

it('fails clearly when s3 node has no seaweedfs tool row', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');

    app(S3RouteRegistrar::class)->syncServiceRoute();
})->throws(RuntimeException::class, 'The S3 service route requires at least one active seaweedfs tool row.')
    ->group('service');

it('updates the service route when called again', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
    ]);

    app(S3RouteRegistrar::class)->syncServiceRoute();
    app(S3RouteRegistrar::class)->syncServiceRoute();

    expect(ProxyRoute::query()->where('domain', 's3.orbit')->count())->toBe(1);
})->group('service');

it('syncs public s3 host as ingress route forwarding to s3.orbit', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    s3AssignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => ['s3.example.com'],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncPublicHosts($tool);

    $route = ProxyRoute::query()->where('domain', 's3.example.com')->firstOrFail();

    expect($route)
        ->node_id->toBe($edge->id)
        ->owner_type->toBe('s3')
        ->kind->toBe('proxy')
        ->and($route->config)->toMatchArray([
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit'],
        ]);
})->group('service');

it('skips ingress route sync when there are no public hosts', function (): void {
    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
    ]);

    app(S3RouteRegistrar::class)->syncPublicHosts($tool);

    expect(ProxyRoute::query()->where('owner_type', 's3')->count())->toBe(0);
})->group('service');

it('removes the public host route when owner_type is tool and owner_name is seaweedfs', function (): void {
    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    s3AssignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => ['s3.example.com']],
    ]);

    ProxyRoute::factory()->create([
        'domain' => 's3.example.com',
        'node_id' => $edge->id,
        'owner_type' => 's3',
        'kind' => 'proxy',
        'config' => ['owner_name' => 'seaweedfs', 'protocol' => 's3', 'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit']],
    ]);

    app(S3RouteRegistrar::class)->removePublicHost($tool, 's3.example.com');

    expect(ProxyRoute::query()->where('domain', 's3.example.com')->exists())->toBeFalse();
})->group('service');

// ---------------------------------------------------------------------------
// Public-host ingress route tests
// ---------------------------------------------------------------------------

it('registers an ingress-placement route on the ingress node targeting the router', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    s3AssignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => ['s3.example.com'],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncPublicHosts($tool);

    $route = ProxyRoute::query()->where('domain', 's3.example.com')->firstOrFail();

    expect($route)
        ->node_id->toBe($edge->id)
        ->owner_type->toBe('s3')
        ->kind->toBe('proxy')
        ->and($route->config['placement'])->toBe('ingress')
        ->and($route->config['router_upstream']['node_id'])->toBe($router->id)
        ->and($route->config['router_upstream']['node'])->toBe('gateway-1')
        ->and($route->config['router_upstream']['url'])->toBe('http://10.6.0.1:80')
        ->and($route->config['target'])->toBe(['type' => 'upstream', 'value' => 'https://s3.orbit']);
})->group('public');

it('public ingress route preserves Host and forwarded-proto via router_upstream config', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    s3AssignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => ['s3.example.com'],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncPublicHosts($tool);

    $route = ProxyRoute::query()->where('domain', 's3.example.com')->firstOrFail();
    $config = $route->config;

    // placement=ingress triggers ProxyRouteRenderer::renderIngress which emits
    // `header_up Host {host}` and `header_up X-Forwarded-Proto {scheme}`.
    expect($config['placement'])->toBe('ingress')
        ->and($config['router_upstream'])->toBeArray()
        ->and($config['router_upstream']['url'])->toStartWith('http://');

    // Confirm the renderer actually produces a Caddy block with the expected
    // host-preservation directives.
    $renderer = app(ProxyRouteRenderer::class);
    $rendered = $renderer->render($route);

    expect($rendered)
        ->toContain('header_up Host {host}')
        ->toContain('header_up X-Forwarded-Proto {scheme}')
        ->toContain('reverse_proxy http://10.6.0.1:80');
})->group('public');

it('creates separate ingress routes for each public host on the same seaweedfs tool', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    s3AssignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => ['s3.example.com', 'files.example.com'],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncPublicHosts($tool);

    expect(ProxyRoute::query()->where('owner_type', 's3')->count())->toBe(2);

    foreach (['s3.example.com', 'files.example.com'] as $host) {
        $route = ProxyRoute::query()->where('domain', $host)->firstOrFail();
        expect($route->node_id)->toBe($edge->id)
            ->and($route->config['placement'])->toBe('ingress')
            ->and($route->config['tls']['cert_path'])->toBe("/etc/orbit/certs/{$host}.crt")
            ->and($route->config['tls']['key_path'])->toBe("/etc/orbit/certs/{$host}.key");
    }
})->group('public');

it('public host route does not target the concrete s3 storage node', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    s3AssignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => ['s3.example.com'],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncPublicHosts($tool);

    $route = ProxyRoute::query()->where('domain', 's3.example.com')->firstOrFail();

    // The route must NOT target the storage node's WireGuard address or backend host.
    $targetValue = $route->config['target']['value'];
    $routerUrl = $route->config['router_upstream']['url'];

    expect($targetValue)->toBe('https://s3.orbit')
        ->and($targetValue)->not->toContain('10.6.0.44')
        ->and($targetValue)->not->toContain('storage-1.s3.orbit')
        ->and($routerUrl)->toContain('10.6.0.1')
        ->and($routerUrl)->not->toContain('10.6.0.44');
})->group('public');

it('re-syncing a public host is idempotent and does not create duplicate routes', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    s3AssignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => ['s3.example.com'],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncPublicHosts($tool);
    app(S3RouteRegistrar::class)->syncPublicHosts($tool);

    expect(ProxyRoute::query()->where('domain', 's3.example.com')->count())->toBe(1);
})->group('public');

it('removePublicHost removes only the seaweedfs s3 owned route and leaves unrelated routes intact', function (): void {
    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    s3AssignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => ['s3.example.com']],
    ]);

    // The route owned by this seaweedfs S3 publication.
    ProxyRoute::factory()->create([
        'domain' => 's3.example.com',
        'node_id' => $edge->id,
        'owner_type' => 's3',
        'kind' => 'proxy',
        'config' => [
            'placement' => 'ingress',
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit'],
        ],
    ]);

    // An unrelated custom route sharing the same node — must not be touched.
    ProxyRoute::factory()->create([
        'domain' => 'other.example.com',
        'node_id' => $edge->id,
        'owner_type' => 'custom',
        'kind' => 'proxy',
        'config' => ['target' => 'https://somewhere-else.example.com'],
    ]);

    app(S3RouteRegistrar::class)->removePublicHost($tool, 's3.example.com');

    expect(ProxyRoute::query()->where('domain', 's3.example.com')->exists())->toBeFalse()
        ->and(ProxyRoute::query()->where('domain', 'other.example.com')->exists())->toBeTrue();
})->group('public');

it('removePublicHost does not remove a non-s3 tool route at the same domain', function (): void {
    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    s3AssignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
    ]);

    // A tool route at the same domain but for a different protocol.
    ProxyRoute::factory()->create([
        'domain' => 's3.example.com',
        'node_id' => $edge->id,
        'owner_type' => 'tool',
        'kind' => 'proxy',
        'config' => ['owner_name' => 'seaweedfs', 'protocol' => 'websocket', 'target' => 'https://other.orbit'],
    ]);

    app(S3RouteRegistrar::class)->removePublicHost($tool, 's3.example.com');

    // The non-s3 tool route must survive.
    expect(ProxyRoute::query()->where('domain', 's3.example.com')->exists())->toBeTrue();
})->group('public');

it('fails clearly when the router node has no WireGuard address', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => null]);
    s3AssignRole($router, 'router');

    $edge = Node::factory()->create(['name' => 'edge-1', 'wireguard_address' => '10.6.0.10']);
    s3AssignRole($edge, 'ingress');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => ['s3.example.com'],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncPublicHosts($tool);
})->throws(RuntimeException::class, 'requires a WireGuard address for S3 public host ingress')
    ->group('public');

it('fails clearly when there is no active ingress node for a public host', function (): void {
    $router = Node::factory()->create(['name' => 'gateway-1', 'wireguard_address' => '10.6.0.1']);
    s3AssignRole($router, 'router');

    $storage = Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    s3AssignRole($storage, 's3');
    $tool = NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'config' => [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => ['s3.example.com'],
        ],
    ]);

    app(S3RouteRegistrar::class)->syncPublicHosts($tool);
})->throws(RuntimeException::class, 'The S3 public host route requires an active ingress node.')
    ->group('public');
