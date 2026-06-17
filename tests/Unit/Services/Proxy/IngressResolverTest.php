<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Proxy\IngressResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('IngressResolver', function (): void {
    it('resolves the app-prod selected ingress node', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);

        $web = Node::factory()->create(['name' => 'web-1', 'status' => 'active']);
        NodeRoleAssignment::factory()->create([
            'node_id' => $web->id,
            'role' => 'app-prod',
            'status' => 'active',
            'settings' => ['ingress_node_id' => $edge->id],
        ]);

        expect(app(IngressResolver::class)->forAppNode($web)->is($edge))->toBeTrue();
    });

    it('rejects missing inactive or ineligible ingress nodes', function (?string $nodeStatus, ?string $roleStatus): void {
        $edge = Node::factory()->create(['name' => 'edge-1', 'status' => $nodeStatus ?? 'active']);

        if ($roleStatus !== null) {
            NodeRoleAssignment::factory()->create([
                'node_id' => $edge->id,
                'role' => 'ingress',
                'status' => $roleStatus,
            ]);
        }

        $web = Node::factory()->create(['name' => 'web-1', 'status' => 'active']);
        NodeRoleAssignment::factory()->create([
            'node_id' => $web->id,
            'role' => 'app-prod',
            'status' => 'active',
            'settings' => ['ingress_node_id' => $edge->id],
        ]);

        app(IngressResolver::class)->forAppNode($web);
    })->with([
        'inactive node' => ['removing', 'active'],
        'missing active role' => ['active', 'pending'],
        'missing role assignment' => ['active', null],
    ])->throws(DomainException::class);

    it('rejects missing ingress node references', function (): void {
        $web = Node::factory()->create(['name' => 'web-1', 'status' => 'active']);
        NodeRoleAssignment::factory()->create([
            'node_id' => $web->id,
            'role' => 'app-prod',
            'status' => 'active',
            'settings' => ['ingress_node_id' => 999999],
        ]);

        app(IngressResolver::class)->forAppNode($web);
    })->throws(DomainException::class);

    it('builds backend urls from wireguard addresses', function (): void {
        $web = Node::factory()->create([
            'name' => 'web-1',
            'wireguard_address' => '10.6.0.21',
        ]);

        expect(app(IngressResolver::class)->backendUrl($web))->toBe('http://10.6.0.21:8081');
    });

    it('resolves the active router node for ingress routes', function (): void {
        $gateway = Node::factory()->create([
            'name' => 'gateway-1',
            'status' => 'active',
            'wireguard_address' => '10.6.0.2',
        ]);
        NodeRoleAssignment::factory()->create(['node_id' => $gateway->id, 'role' => 'router', 'status' => 'active']);

        $router = app(IngressResolver::class)->router();

        expect($router->is($gateway))->toBeTrue()
            ->and(app(IngressResolver::class)->routerUrl($router))->toBe('http://10.6.0.2:80');
    });

    it('rejects router urls for router nodes without wireguard addresses', function (): void {
        $gateway = Node::factory()->create([
            'name' => 'gateway-1',
            'status' => 'active',
            'wireguard_address' => null,
        ]);
        NodeRoleAssignment::factory()->create(['node_id' => $gateway->id, 'role' => 'router', 'status' => 'active']);

        app(IngressResolver::class)->routerUrl($gateway);
    })->throws(DomainException::class, 'Router node requires a WireGuard address for ingress.');

    it('rejects backend urls for app nodes without wireguard addresses', function (): void {
        $web = Node::factory()->create([
            'name' => 'web-1',
            'wireguard_address' => null,
        ]);

        app(IngressResolver::class)->backendUrl($web);
    })->throws(DomainException::class, 'App-production backend node requires a WireGuard address for ingress.');
});
