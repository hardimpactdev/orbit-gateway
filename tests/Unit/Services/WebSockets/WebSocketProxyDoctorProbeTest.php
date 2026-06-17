<?php

declare(strict_types=1);

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Services\WebSockets\WebSocketProxyDoctorProbe;
use App\Services\WebSockets\WebSocketRouteRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Topology helpers
// ---------------------------------------------------------------------------

function wsProbeRouter(array $overrides = []): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'router-1',
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

function wsProbeIngress(array $overrides = []): Node
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

function wsProbeActiveWebSocketNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'wireguard_address' => '10.6.0.44',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'websocket',
        'status' => 'active',
    ]);

    return $node;
}

// ---------------------------------------------------------------------------
// router_route_orphaned — websocket.orbit exists, no active websocket role
// ---------------------------------------------------------------------------

it('ws router_route_orphaned when websocket.orbit route exists and no active websocket role remains', function (): void {
    $router = wsProbeRouter();
    // No active websocket role assignment

    ProxyRoute::factory()->create([
        'domain' => WebSocketRouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['protocol' => 'websocket'],
    ]);

    $probe = app(WebSocketProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->toContain(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey);

    $entry = $drift[array_search(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey, $keys)];
    expect($entry->kind)->toBe(DriftKind::Extra);
})->group('websocket', 'proxy-doctor');

it('ws router_route_orphaned not emitted when active websocket role exists', function (): void {
    $router = wsProbeRouter();
    wsProbeActiveWebSocketNode();

    ProxyRoute::factory()->create([
        'domain' => WebSocketRouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['protocol' => 'websocket'],
    ]);

    $probe = app(WebSocketProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey);
})->group('websocket', 'proxy-doctor');

it('ws router_route_orphaned not emitted when websocket.orbit route does not exist', function (): void {
    $router = wsProbeRouter();
    // No active websocket role, no route row either

    $probe = app(WebSocketProxyDoctorProbe::class);
    $drift = $probe->drift($router);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey);
})->group('websocket', 'proxy-doctor');

it('ws router_route_orphaned not emitted for non-router nodes', function (): void {
    $ingress = wsProbeIngress();
    // No active websocket role

    ProxyRoute::factory()->create([
        'domain' => WebSocketRouteRegistrar::ServiceDomain,
        'node_id' => $ingress->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['protocol' => 'websocket'],
    ]);

    $probe = app(WebSocketProxyDoctorProbe::class);
    $drift = $probe->drift($ingress);

    $keys = array_map(fn ($e) => $e->key, $drift);
    expect($keys)->not->toContain(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey);
})->group('websocket', 'proxy-doctor');

// ---------------------------------------------------------------------------
// restore — router_route_orphaned removes the service route row
// ---------------------------------------------------------------------------

it('ws restore router_route_orphaned removes the websocket.orbit service route row', function (): void {
    $router = wsProbeRouter();

    ProxyRoute::factory()->create([
        'domain' => WebSocketRouteRegistrar::ServiceDomain,
        'node_id' => $router->id,
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => ['protocol' => 'websocket'],
    ]);

    $probe = app(WebSocketProxyDoctorProbe::class);
    $entry = new DriftEntry(
        family: 'proxy',
        key: WebSocketProxyDoctorProbe::RouterRouteOrphanedKey,
        kind: DriftKind::Extra,
        summary: 'Orphaned websocket.orbit route.',
    );

    $result = $probe->restore($router, $entry);

    expect($result)->not->toBeNull()
        ->and($result['key'])->toBe(WebSocketProxyDoctorProbe::RouterRouteOrphanedKey)
        ->and($result['status'])->toBe('completed')
        ->and($result['mode'])->toBe('fix')
        ->and(ProxyRoute::query()->where('domain', WebSocketRouteRegistrar::ServiceDomain)->exists())->toBeFalse();
})->group('websocket', 'proxy-doctor');

it('ws restore returns null for unknown key', function (): void {
    $router = wsProbeRouter();

    $probe = app(WebSocketProxyDoctorProbe::class);
    $entry = new DriftEntry(
        family: 'proxy',
        key: 'proxy.unknown.key',
        kind: DriftKind::Missing,
        summary: 'test',
    );

    $result = $probe->restore($router, $entry);
    expect($result)->toBeNull();
})->group('websocket', 'proxy-doctor');
