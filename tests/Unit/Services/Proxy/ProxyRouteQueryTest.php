<?php

declare(strict_types=1);

use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Proxy\ProxyRouteQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function grantProxyRouteQueryAccess(Node $caller, Node $servingNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProxyRouteQuery', function (): void {
    it('normalizes proxy route entities and sorts them by node then domain', function (): void {
        $zNode = Node::factory()->create(['name' => 'z-node']);
        $aNode = Node::factory()->create(['name' => 'a-node']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $aNode->id]);

        ProxyRoute::factory()->create([
            'node_id' => $zNode->id,
            'domain' => 'z.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => [
                'target' => ['value' => 'https://docs.test'],
                'code' => 302,
            ],
        ]);

        ProxyRoute::factory()->create([
            'node_id' => $aNode->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'tls' => [
                    'managed_by' => 'orbit',
                    'trusted_by_gateway_ca' => true,
                ],
            ],
        ]);

        $result = app(ProxyRouteQuery::class)->list();

        expect(array_column($result['routes'], 'domain'))->toBe(['docs.test', 'z.test'])
            ->and($result['meta'])->toBe([
                'filter' => 'all',
                'node' => null,
                'count' => 2,
            ])
            ->and($result['routes'][0])->toMatchArray([
                'domain' => 'docs.test',
                'kind' => 'app',
                'owner' => ['type' => 'app', 'name' => 'docs'],
                'node' => 'a-node',
                'target' => ['type' => 'app', 'value' => 'docs'],
                'redirect_code' => null,
                'tls' => ['managed_by' => 'orbit', 'trusted_by_gateway_ca' => true],
                'status' => 'expected',
            ])
            ->and($result['routes'][1])->toMatchArray([
                'domain' => 'z.test',
                'kind' => 'redirect',
                'owner' => ['type' => 'custom', 'name' => null],
                'target' => ['type' => 'redirect', 'value' => 'https://docs.test'],
                'redirect_code' => 302,
            ]);
    });

    it('includes ingress placement and router backend pool metadata for production routes', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1']);
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $backend = Node::factory()->create(['name' => 'web-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $backend->id, 'environment' => 'production']);

        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    ['node_id' => $backend->id, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80'],
                ],
                'backend_artifacts' => [
                    ['node_id' => $backend->id, 'bind' => '10.6.0.21'],
                ],
            ],
        ]);

        $route = app(ProxyRouteQuery::class)->list()['routes'][0];

        expect($route)->toMatchArray([
            'domain' => 'docs.test',
            'node' => 'edge-1',
            'placement' => 'ingress',
            'router' => [
                'node' => 'gateway-1',
                'url' => 'http://10.6.0.2:80',
                'backend_pool' => [
                    ['node' => 'web-1', 'url' => 'http://10.6.0.21:80'],
                ],
            ],
        ])
            ->and($route['router']['backend_pool'][0])->not->toHaveKey('node_id');
    });

    it('normalizes websocket service routes and selects them via the websocket service filter', function (): void {
        $router = Node::factory()->router()->create(['name' => 'router-1']);

        ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => [
                'protocol' => 'websocket',
                'router_backend_pool' => [
                    ['node_id' => 42, 'node' => 'app-dev-1', 'url' => 'https://10.6.0.44:8080'],
                ],
                'tls' => [
                    'managed_by' => 'internal',
                    'trusted_by_gateway_ca' => true,
                ],
            ],
        ]);

        $result = app(ProxyRouteQuery::class)->list(filter: 'websocket');

        expect($result['meta'])->toBe([
            'filter' => 'websocket',
            'node' => null,
            'count' => 1,
        ])
            ->and($result['routes'][0])->toMatchArray([
                'domain' => 'websocket.orbit',
                'kind' => 'proxy',
                'owner' => ['type' => 'router', 'name' => 'websocket.orbit'],
                'node' => 'router-1',
                'target' => ['type' => 'upstream', 'value' => 'websocket.orbit'],
                'tls' => ['managed_by' => 'internal', 'trusted_by_gateway_ca' => true],
            ]);
    });

    it('websocket service filter does not include non-service-domain router routes', function (): void {
        $router = Node::factory()->router()->create(['name' => 'router-1']);

        // A router-owned route at a different domain — must NOT appear under websocket filter
        ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'other.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => ['protocol' => 'other'],
        ]);

        // The websocket.orbit service route — must appear
        ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'websocket.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => ['protocol' => 'websocket'],
        ]);

        $result = app(ProxyRouteQuery::class)->list(filter: 'websocket');

        expect($result['meta']['count'])->toBe(1)
            ->and($result['routes'][0]['domain'])->toBe('websocket.orbit');
    });

    it('normalizes app websocket public routes and filters them by owner', function (): void {
        $edge = Node::factory()->ingress()->create(['name' => 'edge-1']);
        $router = Node::factory()->router()->create(['name' => 'router-1']);
        $appNode = Node::factory()->appProd()->create(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);

        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'ws.docs.test',
            'owner_type' => 'app-websocket',
            'kind' => 'proxy',
            'config' => [
                'placement' => 'ingress',
                'target' => [
                    'type' => 'websocket',
                    'value' => 'https://websocket.orbit',
                ],
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'router-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    ['node_id' => $router->id, 'node' => 'router-1', 'url' => 'https://websocket.orbit'],
                ],
            ],
        ]);

        $result = app(ProxyRouteQuery::class)->list(filter: 'app-websocket');

        expect($result['meta'])->toBe([
            'filter' => 'app-websocket',
            'node' => null,
            'count' => 1,
        ])
            ->and($result['routes'][0])->toMatchArray([
                'domain' => 'ws.docs.test',
                'kind' => 'proxy',
                'owner' => ['type' => 'app-websocket', 'name' => 'docs'],
                'node' => 'edge-1',
                'target' => ['type' => 'websocket', 'value' => 'https://websocket.orbit'],
                'placement' => 'ingress',
                'router' => [
                    'node' => 'router-1',
                    'url' => 'http://10.6.0.2:80',
                    'backend_pool' => [
                        ['node' => 'router-1', 'url' => 'https://websocket.orbit'],
                    ],
                ],
            ]);
    });

    it('applies route filters after visibility is resolved', function (): void {
        $node = Node::factory()->create(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature', 'app_id' => $app->id]);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'domain' => 'feature.docs.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'custom.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['upstream' => 'http://127.0.0.1:9000'],
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'old.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
        ]);

        $query = app(ProxyRouteQuery::class);

        expect(array_column($query->list(filter: 'app')['routes'], 'domain'))->toBe(['docs.test'])
            ->and(array_column($query->list(filter: 'workspace')['routes'], 'domain'))->toBe(['feature.docs.test'])
            ->and(array_column($query->list(filter: 'custom')['routes'], 'domain'))->toBe(['custom.test'])
            ->and(array_column($query->list(filter: 'redirect')['routes'], 'domain'))->toBe(['old.test']);
    });

    it('s3 service filter selects router-owned s3.orbit route and public s3 host routes', function (): void {
        $router = Node::factory()->router()->create(['name' => 'router-1']);
        $edge = Node::factory()->ingress()->create(['name' => 'edge-1']);

        // The router-owned s3.orbit service route
        ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 's3.orbit',
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => ['protocol' => 's3'],
        ]);

        // A public S3 host route (owner s3)
        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'domain' => 's3.example.com',
            'owner_type' => 's3',
            'kind' => 'proxy',
            'config' => ['placement' => 'ingress', 'protocol' => 's3'],
        ]);

        // A route with a different owner — must NOT appear
        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'domain' => 'app.example.com',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $result = app(ProxyRouteQuery::class)->list(filter: 's3');

        $domains = array_column($result['routes'], 'domain');
        sort($domains);

        expect($domains)->toBe(['s3.example.com', 's3.orbit'])
            ->and($result['meta']['count'])->toBe(2);
    });

    it('filters by visible serving node and rejects unknown node scope', function (): void {
        $caller = Node::factory()->appDev()->create();
        $visibleNode = Node::factory()->create(['name' => 'visible-node']);
        $hiddenNode = Node::factory()->create(['name' => 'hidden-node']);
        grantProxyRouteQueryAccess($caller, $visibleNode);

        ProxyRoute::factory()->create(['node_id' => $visibleNode->id, 'domain' => 'visible.test']);
        ProxyRoute::factory()->create(['node_id' => $hiddenNode->id, 'domain' => 'hidden.test']);

        $query = app(ProxyRouteQuery::class);
        $result = $query->list(node: 'visible-node', caller: $caller);

        expect(array_column($result['routes'], 'domain'))->toBe(['visible.test'])
            ->and($result['meta']['node'])->toBe('visible-node');

        $query->list(node: 'hidden-node', caller: $caller);
    })->throws(GatewayApiException::class, "Unknown node: 'hidden-node'.");

    it('fails authorization when app callers have no route visibility', function (): void {
        $caller = Node::factory()->appDev()->create();

        app(ProxyRouteQuery::class)->list(caller: $caller);
    })->throws(GatewayApiException::class, 'This node is not authorized to read the proxy route registry.');
});
