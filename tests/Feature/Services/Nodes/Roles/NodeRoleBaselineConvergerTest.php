<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('node role caddy baseline convergence', function (): void {
    it('converges orbit-caddy intent when a role needs caddy', function (string $role, array $settings, array $expectedPorts): void {
        $node = todo314CaddyBaselineNode();
        $assignment = NodeRoleAssignment::factory()->for($node)->create([
            'role' => $role,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => $settings,
        ]);

        app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'caddy')
            ->sole();

        $container = is_array($tool->config) ? ($tool->config['container'] ?? null) : null;

        expect($tool->expected_state)->toBe('installed')
            ->and($container)->toBeArray()
            ->and($container['name'] ?? null)->toBe('orbit-caddy')
            ->and($container['image'] ?? null)->toBe('caddy:2-alpine')
            ->and($container['restart_policy'] ?? null)->toBe('unless-stopped')
            ->and($container['network'] ?? null)->toBe('orbit-network')
            ->and($container['published_ports'] ?? null)->toBe($expectedPorts)
            ->and($container['extra_hosts'] ?? null)->toBe(['host.docker.internal' => 'host-gateway']);

        $mountTargets = collect($container['mounts'] ?? [])->pluck('target')->all();

        expect($mountTargets)->toContain('/etc/caddy/Caddyfile', '/etc/caddy/orbit', '/etc/caddy/sites', '/etc/orbit', '/home');
    })->with([
        'app-dev' => [
            NodeRoleName::AppDevelopment->value,
            ['tld' => 'test'],
            ['10.6.0.50:80:80', '10.6.0.50:443:443', '10.6.0.50:443:443/udp', '10.6.0.50:8081:8081'],
        ],
        'app-prod' => [
            NodeRoleName::AppProduction->value,
            [],
            ['10.6.0.50:80:80', '10.6.0.50:443:443', '10.6.0.50:443:443/udp', '10.6.0.50:8081:8081'],
        ],
        'router' => [
            NodeRoleName::Router->value,
            [],
            ['10.6.0.50:80:80', '10.6.0.50:443:443', '10.6.0.50:443:443/udp', '10.6.0.50:8081:8081'],
        ],
        'ingress' => [
            NodeRoleName::Ingress->value,
            [],
            ['80:80', '443:443', '443:443/udp', '10.6.0.50:8081:8081'],
        ],
    ]);

    it('keeps at most one standalone orbit-caddy intent when several caddy roles converge on one node', function (): void {
        $node = todo314CaddyBaselineNode();
        $converger = app(NodeRoleBaselineConverger::class);

        foreach ([NodeRoleName::Router->value, NodeRoleName::Ingress->value, NodeRoleName::AppProduction->value] as $role) {
            $assignment = NodeRoleAssignment::factory()->for($node)->create([
                'role' => $role,
                'status' => NodeRoleStatus::Pending->value,
            ]);

            $converger->converge($node, $assignment);
        }

        expect(NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'caddy')
            ->count())->toBe(1);

        $container = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'caddy')
            ->sole()
            ->config['container'] ?? null;

        expect($container)->toBeArray()
            ->and($container['published_ports'] ?? null)->toBe(['80:80', '443:443', '443:443/udp', '10.6.0.50:8081:8081']);
    });

    it('keeps the orbit-caddy private backend port off the public socket when ingress and app-prod co-locate', function (): void {
        $node = todo314CaddyBaselineNode();
        $converger = app(NodeRoleBaselineConverger::class);

        foreach ([NodeRoleName::Ingress->value, NodeRoleName::AppProduction->value] as $role) {
            $assignment = NodeRoleAssignment::factory()->for($node)->create([
                'role' => $role,
                'status' => NodeRoleStatus::Pending->value,
            ]);

            $converger->converge($node, $assignment);
        }

        $container = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'caddy')
            ->sole()
            ->config['container'] ?? null;

        expect($container)->toBeArray();

        $publicPorts = collect($container['published_ports'] ?? [])
            ->reject(fn (string $port): bool => str_starts_with($port, '10.6.0.50:'))
            ->values()
            ->all();

        foreach ($publicPorts as $publicPort) {
            expect($publicPort)->not->toContain('8081');
        }

        expect($container['published_ports'])->toContain('10.6.0.50:8081:8081');
    });
});

function todo314CaddyBaselineNode(): Node
{
    return Node::factory()->create([
        'platform' => 'ubuntu',
        'host' => '10.6.0.50',
        'wireguard_address' => '10.6.0.50',
        'status' => NodeStatus::Active,
    ]);
}
