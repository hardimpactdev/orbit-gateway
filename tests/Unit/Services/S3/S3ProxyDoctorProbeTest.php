<?php

declare(strict_types=1);

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\S3\S3ProxyDoctorProbe;
use App\Services\S3\S3RouteRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Topology helpers
// ---------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $overrides
 */
function s3ProxyRouter(array $overrides = []): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'gateway-1',
        'wireguard_address' => '10.6.0.1',
        'status' => 'active',
    ], $overrides));

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'router',
        'status' => 'active',
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function s3ProxyIngress(array $overrides = []): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'edge-1',
        'wireguard_address' => '10.6.0.10',
        'status' => 'active',
    ], $overrides));

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'ingress',
        'status' => 'active',
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $toolConfig
 */
function s3ProxyStorageNode(string $backendHost = 'storage-1.s3.orbit', array $toolConfig = []): array
{
    $node = Node::factory()->create([
        'name' => 'storage-1',
        'wireguard_address' => '10.6.0.44',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 's3',
        'status' => 'active',
    ]);

    $tool = NodeTool::factory()->create(array_merge([
        'node_id' => $node->id,
        'name' => 'seaweedfs',
        'config' => [
            'backend_host' => $backendHost,
            'public_hosts' => [],
        ],
    ], ['config' => array_merge([
        'backend_host' => $backendHost,
        'public_hosts' => [],
    ], $toolConfig)]));

    return [$node, $tool];
}

// ---------------------------------------------------------------------------
// routerRouteDrift — router_route_missing
// ---------------------------------------------------------------------------

it('s3 router_route_missing when s3.orbit route is absent', function (): void {
    $router = s3ProxyRouter();
    s3ProxyStorageNode();

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->toContain(S3ProxyDoctorProbe::RouterRouteKey);

    $entry = $drift[array_search(S3ProxyDoctorProbe::RouterRouteKey, $keys)];
    expect($entry->kind)->toBe(DriftKind::Missing);
})->group('s3', 'proxy-doctor');

it('s3 router_route_missing when s3.orbit route is divergent from intent', function (): void {
    $router = s3ProxyRouter();
    s3ProxyStorageNode();

    // Create a route with wrong node_id (divergent intent)
    ProxyRoute::factory()->create([
        'domain' => 's3.orbit',
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'source_hash' => 'wrong-hash',
        'config' => [
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'http://storage-1.s3.orbit:8333'],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'storage-1.s3.orbit', 'port' => 8333],
            ],
        ],
    ]);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->toContain(S3ProxyDoctorProbe::RouterRouteKey);

    $entry = $drift[array_search(S3ProxyDoctorProbe::RouterRouteKey, $keys)];
    expect($entry->kind)->toBe(DriftKind::Divergent);
})->group('s3', 'proxy-doctor');

it('s3 no router drift when s3.orbit route matches intent exactly', function (): void {
    $router = s3ProxyRouter();
    s3ProxyStorageNode();

    // Sync to create the canonical route
    app(S3RouteRegistrar::class)->syncServiceRoute();

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $routerKeys = array_filter(
        array_map(fn ($e) => $e->key, $drift),
        fn ($k) => $k === S3ProxyDoctorProbe::RouterRouteKey || $k === S3ProxyDoctorProbe::RouterBackendKey,
    );
    expect(array_values($routerKeys))->toBe([]);
})->group('s3', 'proxy-doctor');

it('s3 no router drift when node is not a router', function (): void {
    $ingress = s3ProxyIngress();
    s3ProxyStorageNode();

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($ingress);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(S3ProxyDoctorProbe::RouterRouteKey);
    expect($keys)->not->toContain(S3ProxyDoctorProbe::RouterBackendKey);
})->group('s3', 'proxy-doctor');

it('s3 no router drift when there are no active s3 nodes', function (): void {
    $router = s3ProxyRouter();
    // No s3 nodes

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(S3ProxyDoctorProbe::RouterRouteKey);
})->group('s3', 'proxy-doctor');

// ---------------------------------------------------------------------------
// routerBackendDrift — router_backend_invalid
// ---------------------------------------------------------------------------

it('s3 router_backend_invalid when the upstreams list is empty', function (): void {
    $router = s3ProxyRouter();
    s3ProxyStorageNode();

    ProxyRoute::factory()->create([
        'domain' => 's3.orbit',
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'source_hash' => 'some-hash',
        'config' => [
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'http://storage-1.s3.orbit:8333'],
            'upstreams' => [],
        ],
    ]);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->toContain(S3ProxyDoctorProbe::RouterBackendKey);

    $entry = $drift[array_search(S3ProxyDoctorProbe::RouterBackendKey, $keys)];
    expect($entry->kind)->toBe(DriftKind::Divergent);
})->group('s3', 'proxy-doctor');

it('s3 router_backend_invalid when upstreams point to a raw IP (non-.s3.orbit host)', function (): void {
    $router = s3ProxyRouter();
    s3ProxyIngress();
    s3ProxyStorageNode();

    // Backend pool points to a raw IP (not a .s3.orbit hostname) — simulating
    // a mis-configured pool that references a node's WireGuard address directly.
    ProxyRoute::factory()->create([
        'domain' => 's3.orbit',
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'source_hash' => 'some-hash',
        'config' => [
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'http://10.6.0.10:8333'],
            'upstreams' => [
                ['scheme' => 'http', 'host' => '10.6.0.10', 'port' => 8333],
            ],
        ],
    ]);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->toContain(S3ProxyDoctorProbe::RouterBackendKey);
})->group('s3', 'proxy-doctor');

it('s3 router_backend_invalid when upstreams point to a host not ending with .s3.orbit', function (): void {
    $router = s3ProxyRouter();
    s3ProxyStorageNode();

    ProxyRoute::factory()->create([
        'domain' => 's3.orbit',
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'source_hash' => 'some-hash',
        'config' => [
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'http://external.example.com:8333'],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'external.example.com', 'port' => 8333],
            ],
        ],
    ]);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->toContain(S3ProxyDoctorProbe::RouterBackendKey);
})->group('s3', 'proxy-doctor');

it('s3 router_backend_invalid not emitted when route is absent (router_route_missing covers it)', function (): void {
    $router = s3ProxyRouter();
    s3ProxyStorageNode();
    // No s3.orbit route at all

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    // route is absent → router_route_missing fires, NOT router_backend_invalid
    expect($keys)->toContain(S3ProxyDoctorProbe::RouterRouteKey);
    expect($keys)->not->toContain(S3ProxyDoctorProbe::RouterBackendKey);
})->group('s3', 'proxy-doctor');

// ---------------------------------------------------------------------------
// Non-overlap: router_route_missing vs router_backend_invalid
// ---------------------------------------------------------------------------

it('s3 pure intent-drift reports router_route_missing not router_backend_invalid', function (): void {
    $router = s3ProxyRouter();
    s3ProxyStorageNode();

    // Route exists but source_hash is wrong (intent mismatch), backend is valid
    ProxyRoute::factory()->create([
        'domain' => 's3.orbit',
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'source_hash' => 'intentionally-wrong-hash',
        'config' => [
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'http://storage-1.s3.orbit:8333'],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'storage-1.s3.orbit', 'port' => 8333],
            ],
        ],
    ]);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    // Intent drift → router_route_missing (Divergent)
    expect($keys)->toContain(S3ProxyDoctorProbe::RouterRouteKey);
    // Backend content is valid → backend_invalid must NOT fire
    expect($keys)->not->toContain(S3ProxyDoctorProbe::RouterBackendKey);
})->group('s3', 'proxy-doctor');

it('s3 bad-backend case reports router_backend_invalid not router_route_missing', function (): void {
    $router = s3ProxyRouter();
    s3ProxyStorageNode();

    // Sync the canonical route first so source_hash matches intent
    app(S3RouteRegistrar::class)->syncServiceRoute();

    // Now corrupt the upstreams in-place (backend-invalid scenario)
    $route = ProxyRoute::query()->where('domain', 's3.orbit')->firstOrFail();
    $config = $route->config;
    $config['upstreams'] = [];
    $route->forceFill(['config' => $config])->save();

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    // Empty backend pool → backend_invalid fires
    expect($keys)->toContain(S3ProxyDoctorProbe::RouterBackendKey);
    // route exists → router_route_missing must NOT fire
    expect($keys)->not->toContain(S3ProxyDoctorProbe::RouterRouteKey);
})->group('s3', 'proxy-doctor');

// ---------------------------------------------------------------------------
// publicRouteDrift — public_route_missing
// ---------------------------------------------------------------------------

it('s3 public_route_missing when public host route is absent', function (): void {
    s3ProxyRouter();
    $ingress = s3ProxyIngress();
    [, $tool] = s3ProxyStorageNode(toolConfig: ['public_hosts' => ['s3.example.com']]);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($ingress);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->toContain(S3ProxyDoctorProbe::PublicRouteKey);

    $entry = $drift[array_search(S3ProxyDoctorProbe::PublicRouteKey, $keys)];
    expect($entry->kind)->toBe(DriftKind::Missing);
})->group('s3', 'proxy-doctor');

it('s3 public_route_missing when public host route is divergent', function (): void {
    $router = s3ProxyRouter();
    $ingress = s3ProxyIngress();
    [, $tool] = s3ProxyStorageNode(toolConfig: ['public_hosts' => ['s3.example.com']]);

    // Create a route with wrong source_hash (divergent)
    ProxyRoute::factory()->create([
        'domain' => 's3.example.com',
        'node_id' => $ingress->id,
        'owner_type' => 's3',
        'kind' => 'proxy',
        'source_hash' => 'wrong-public-hash',
        'config' => [
            'placement' => 'ingress',
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit'],
            'router_upstream' => [
                'node_id' => $router->id,
                'node' => 'gateway-1',
                'url' => 'http://10.6.0.1:80',
            ],
            'tls' => [
                'cert_path' => '/etc/orbit/certs/s3.example.com.crt',
                'key_path' => '/etc/orbit/certs/s3.example.com.key',
            ],
        ],
    ]);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($ingress);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->toContain(S3ProxyDoctorProbe::PublicRouteKey);

    $entry = $drift[array_search(S3ProxyDoctorProbe::PublicRouteKey, $keys)];
    expect($entry->kind)->toBe(DriftKind::Divergent);
})->group('s3', 'proxy-doctor');

it('s3 no public drift when public host route matches intent', function (): void {
    $router = s3ProxyRouter();
    $ingress = s3ProxyIngress();
    [, $tool] = s3ProxyStorageNode(toolConfig: ['public_hosts' => ['s3.example.com']]);

    // Sync to create canonical route
    app(S3RouteRegistrar::class)->syncPublicHosts($tool);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($ingress);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(S3ProxyDoctorProbe::PublicRouteKey);
})->group('s3', 'proxy-doctor');

it('s3 no public drift when node is not ingress', function (): void {
    $router = s3ProxyRouter();
    [, $tool] = s3ProxyStorageNode(toolConfig: ['public_hosts' => ['s3.example.com']]);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(S3ProxyDoctorProbe::PublicRouteKey);
})->group('s3', 'proxy-doctor');

it('s3 public drift detail contains seaweedfs_tool_id for restore', function (): void {
    $router = s3ProxyRouter();
    $ingress = s3ProxyIngress();
    [, $tool] = s3ProxyStorageNode(toolConfig: ['public_hosts' => ['s3.example.com']]);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($ingress);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->toContain(S3ProxyDoctorProbe::PublicRouteKey);

    $entry = $drift[array_search(S3ProxyDoctorProbe::PublicRouteKey, $keys)];
    expect($entry->detail)->toHaveKey('seaweedfs_tool_id');
    expect($entry->detail['seaweedfs_tool_id'])->toBe($tool->id);
})->group('s3', 'proxy-doctor');

// ---------------------------------------------------------------------------
// restore paths
// ---------------------------------------------------------------------------

it('s3 restore router_route_missing calls syncServiceRoute and returns fix result', function (): void {
    $router = s3ProxyRouter();
    s3ProxyStorageNode();

    // Ensure the intent can be built
    app(S3RouteRegistrar::class)->syncServiceRoute();

    $probe = app(S3ProxyDoctorProbe::class);
    $entry = new DriftEntry(
        family: 'proxy',
        key: S3ProxyDoctorProbe::RouterRouteKey,
        kind: DriftKind::Missing,
        summary: 'test',
    );

    $result = $probe->restore($router, $entry);

    expect($result)->not->toBeNull()
        ->and($result['key'])->toBe(S3ProxyDoctorProbe::RouterRouteKey)
        ->and($result['status'])->toBe('completed')
        ->and($result['mode'])->toBe('fix');
})->group('s3', 'proxy-doctor');

it('s3 restore router_backend_invalid calls syncServiceRoute and returns fix result', function (): void {
    $router = s3ProxyRouter();
    s3ProxyStorageNode();

    app(S3RouteRegistrar::class)->syncServiceRoute();

    $probe = app(S3ProxyDoctorProbe::class);
    $entry = new DriftEntry(
        family: 'proxy',
        key: S3ProxyDoctorProbe::RouterBackendKey,
        kind: DriftKind::Divergent,
        summary: 'test',
    );

    $result = $probe->restore($router, $entry);

    expect($result)->not->toBeNull()
        ->and($result['key'])->toBe(S3ProxyDoctorProbe::RouterBackendKey)
        ->and($result['status'])->toBe('completed')
        ->and($result['mode'])->toBe('fix');
})->group('s3', 'proxy-doctor');

it('s3 restore public_route_missing calls syncPublicHosts and returns fix result', function (): void {
    $router = s3ProxyRouter();
    $ingress = s3ProxyIngress();
    [, $tool] = s3ProxyStorageNode(toolConfig: ['public_hosts' => ['s3.example.com']]);

    $probe = app(S3ProxyDoctorProbe::class);
    $entry = new DriftEntry(
        family: 'proxy',
        key: S3ProxyDoctorProbe::PublicRouteKey,
        kind: DriftKind::Missing,
        summary: 'test',
        detail: ['seaweedfs_tool_id' => $tool->id],
    );

    $result = $probe->restore($ingress, $entry);

    expect($result)->not->toBeNull()
        ->and($result['key'])->toBe(S3ProxyDoctorProbe::PublicRouteKey)
        ->and($result['status'])->toBe('completed')
        ->and($result['mode'])->toBe('fix');
})->group('s3', 'proxy-doctor');

it('s3 restore public_route_missing returns null when seaweedfs_tool_id is missing from detail', function (): void {
    $router = s3ProxyRouter();
    $ingress = s3ProxyIngress();

    $probe = app(S3ProxyDoctorProbe::class);
    $entry = new DriftEntry(
        family: 'proxy',
        key: S3ProxyDoctorProbe::PublicRouteKey,
        kind: DriftKind::Missing,
        summary: 'test',
        detail: [],
    );

    $result = $probe->restore($ingress, $entry);
    expect($result)->toBeNull();
})->group('s3', 'proxy-doctor');

it('s3 restore returns null for unknown key', function (): void {
    $router = s3ProxyRouter();

    $probe = app(S3ProxyDoctorProbe::class);
    $entry = new DriftEntry(
        family: 'proxy',
        key: 'proxy.unknown.key',
        kind: DriftKind::Missing,
        summary: 'test',
    );

    $result = $probe->restore($router, $entry);
    expect($result)->toBeNull();
})->group('s3', 'proxy-doctor');

// ---------------------------------------------------------------------------
// DoctorReportRunner integration: S3 role categories include proxy
// ---------------------------------------------------------------------------

it('s3 role resolves to categories including proxy', function (): void {
    $runner = app(DoctorReportRunner::class);
    $categories = $runner->categoriesForRole('s3');

    expect($categories)->toContain('node')
        ->toContain('tool')
        ->toContain('proxy');
})->group('s3', 'proxy-doctor');

it('s3 node categories for s3 role node includes proxy', function (): void {
    $node = Node::factory()->create(['name' => 's3-node', 'status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 's3',
        'status' => 'active',
    ]);

    $runner = app(DoctorReportRunner::class);
    $categories = $runner->categoriesForNode($node);

    expect($categories)->toContain('node')
        ->toContain('tool')
        ->toContain('proxy');
})->group('s3', 'proxy-doctor');

// ---------------------------------------------------------------------------
// routerRouteOrphanedDrift — proxy.s3.router_route_orphaned
// ---------------------------------------------------------------------------

it('s3 router_route_orphaned when s3.orbit route exists and no active s3 role remains', function (): void {
    $router = s3ProxyRouter();
    // No active s3 role assignment

    ProxyRoute::factory()->create([
        'domain' => S3RouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['owner_name' => 'seaweedfs', 'protocol' => 's3'],
    ]);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->toContain(S3ProxyDoctorProbe::RouterRouteOrphanedKey);

    $entry = $drift[array_search(S3ProxyDoctorProbe::RouterRouteOrphanedKey, $keys)];
    expect($entry->kind)->toBe(DriftKind::Extra);
})->group('s3', 'proxy-doctor');

it('s3 router_route_orphaned not emitted when active s3 role exists', function (): void {
    $router = s3ProxyRouter();
    s3ProxyStorageNode();

    ProxyRoute::factory()->create([
        'domain' => S3RouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['owner_name' => 'seaweedfs', 'protocol' => 's3'],
    ]);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(S3ProxyDoctorProbe::RouterRouteOrphanedKey);
})->group('s3', 'proxy-doctor');

it('s3 router_route_orphaned not emitted when s3.orbit route does not exist', function (): void {
    $router = s3ProxyRouter();
    // No active s3 role, no route row either

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(S3ProxyDoctorProbe::RouterRouteOrphanedKey);
})->group('s3', 'proxy-doctor');

it('s3 router_route_orphaned not emitted for non-router nodes', function (): void {
    $ingress = s3ProxyIngress();
    // No active s3 role

    ProxyRoute::factory()->create([
        'domain' => S3RouteRegistrar::ServiceDomain,
        'node_id' => $ingress->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['owner_name' => 'seaweedfs', 'protocol' => 's3'],
    ]);

    $probe = app(S3ProxyDoctorProbe::class);
    $drift = $probe->drift($ingress);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(S3ProxyDoctorProbe::RouterRouteOrphanedKey);
})->group('s3', 'proxy-doctor');

it('s3 restore router_route_orphaned removes the s3.orbit service route row', function (): void {
    $router = s3ProxyRouter();

    ProxyRoute::factory()->create([
        'domain' => S3RouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['owner_name' => 'seaweedfs', 'protocol' => 's3'],
    ]);

    $probe = app(S3ProxyDoctorProbe::class);
    $entry = new DriftEntry(
        family: 'proxy',
        key: S3ProxyDoctorProbe::RouterRouteOrphanedKey,
        kind: DriftKind::Extra,
        summary: 'Orphaned s3.orbit route.',
    );

    $result = $probe->restore($router, $entry);

    expect($result)->not->toBeNull()
        ->and($result['key'])->toBe(S3ProxyDoctorProbe::RouterRouteOrphanedKey)
        ->and($result['status'])->toBe('completed')
        ->and($result['mode'])->toBe('fix')
        ->and(ProxyRoute::query()->where('domain', S3RouteRegistrar::ServiceDomain)->exists())->toBeFalse();
})->group('s3', 'proxy-doctor');
