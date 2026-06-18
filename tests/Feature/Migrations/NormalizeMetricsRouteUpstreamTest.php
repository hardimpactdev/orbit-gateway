<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('normalizes legacy metrics route upstream intent for Caddy host access', function (): void {
    $migrationPath = database_path('migrations/2026_06_17_010000_normalize_metrics_route_upstream.php');

    expect(is_file($migrationPath))->toBeTrue();

    $node = Node::factory()->router()->create(['name' => 'gateway']);
    $routeId = DB::table('proxy_routes')->insertGetId([
        'node_id' => $node->id,
        'app_id' => null,
        'workspace_id' => null,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
        'source_hash' => str_repeat('0', 64),
        'config' => json_encode([
            'owner_name' => 'grafana',
            'protocol' => 'http',
            'target' => [
                'type' => 'upstream',
                'value' => 'http://gateway.metrics.orbit:3000',
            ],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'gateway.metrics.orbit', 'port' => 3000],
            ],
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require $migrationPath;
    $migration->up();

    $route = ProxyRoute::query()->findOrFail($routeId);

    expect($route->config)->toMatchArray([
        'owner_name' => 'grafana',
        'protocol' => 'http',
        'target' => [
            'type' => 'upstream',
            'value' => 'http://host.docker.internal:3000',
        ],
        'upstreams' => [
            ['scheme' => 'http', 'host' => 'host.docker.internal', 'port' => 3000],
        ],
    ])->and($route->source_hash)->toBe(app(ProxyRouteRenderer::class)->sourceHash($route));
});
