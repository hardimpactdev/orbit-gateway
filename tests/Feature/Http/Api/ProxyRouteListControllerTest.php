<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROXY_ROUTE_LIST_CALLER_WG_IP = '10.6.0.91';

function createProxyRouteListCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => PROXY_ROUTE_LIST_CALLER_WG_IP,
        'wireguard_address' => PROXY_ROUTE_LIST_CALLER_WG_IP,
    ], $overrides));
}

function grantProxyRouteListAccess(Node $caller, Node $servingNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignProxyRouteListRole(Node $node, string $role = 'gateway'): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $role === 'app-dev' ? ['tld' => 'test'] : [],
    ]);
}

describe('ProxyRouteListController', function (): void {
    it('lists visible proxy routes with filter metadata', function (): void {
        $caller = createProxyRouteListCallerNode();
        $visibleNode = Node::factory()->create(['name' => 'app-1']);
        $hiddenNode = Node::factory()->create(['name' => 'app-2']);
        grantProxyRouteListAccess($caller, $visibleNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $visibleNode->id]);

        ProxyRoute::factory()->create([
            'node_id' => $visibleNode->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $hiddenNode->id,
            'domain' => 'hidden.test',
        ]);

        $response = $this->call('GET', '/api/proxy-routes?filter=app', [], [], [], ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.routes')
            ->assertJsonPath('success.data.routes.0.domain', 'docs.test')
            ->assertJsonPath('success.meta.filter', 'app')
            ->assertJsonPath('success.meta.node', null)
            ->assertJsonPath('success.meta.count', 1);
    });

    it('lets gateway callers read all route intent', function (): void {
        $caller = createProxyRouteListCallerNode();
        assignProxyRouteListRole($caller);

        ProxyRoute::factory()->count(2)->create();

        $response = $this->call('GET', '/api/proxy-routes', [], [], [], ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(2, 'success.data.routes');
    });

    it('returns validation failures for invalid filters and node scopes', function (string $query, string $field): void {
        $caller = createProxyRouteListCallerNode();
        assignProxyRouteListRole($caller);

        $response = $this->call('GET', "/api/proxy-routes?{$query}", [], [], [], ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field);
    })->with([
        'invalid filter' => ['filter=bad', 'filter'],
        'unknown node' => ['node=missing', 'node'],
    ]);

    it('does not grant route visibility to unassigned callers', function (): void {
        createProxyRouteListCallerNode();

        ProxyRoute::factory()->count(2)->create();

        $response = $this->call('GET', '/api/proxy-routes', [], [], [], ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'proxy:read');
    });

    it('returns authorization failure when the caller has no route visibility', function (): void {
        $caller = createProxyRouteListCallerNode();
        assignProxyRouteListRole($caller, 'app-dev');

        $response = $this->call('GET', '/api/proxy-routes', [], [], [], ['REMOTE_ADDR' => PROXY_ROUTE_LIST_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'proxy:read');
    });
});
