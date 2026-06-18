<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runGatewayCoupledVpnRoleBackfillMigration(): void
{
    $migration = require base_path('database/migrations/2026_05_20_000000_backfill_gateway_coupled_vpn_roles.php');

    $migration->up();
}

function rollbackGatewayCoupledVpnRoleBackfillMigration(): void
{
    $migration = require base_path('database/migrations/2026_05_20_000000_backfill_gateway_coupled_vpn_roles.php');

    $migration->down();
}

it('backfills vpn role assignments for active gateway role assignments', function (): void {
    $activeGateway = Node::factory()->create([
        'name' => 'gateway-1',
        'host' => 'gateway-1.internal',
        'gateway_endpoint' => 'gateway.example.com',
        'status' => 'active',
    ]);

    $inactiveGateway = Node::factory()->create([
        'name' => 'gateway-2',
        'host' => 'gateway-2.internal',
        'gateway_endpoint' => 'gateway-2.example.com',
        'status' => 'inactive',
    ]);

    $existingVpnGateway = Node::factory()->create([
        'name' => 'gateway-3',
        'host' => 'gateway-3.internal',
        'gateway_endpoint' => null,
        'status' => 'active',
    ]);

    $hostFallbackGateway = Node::factory()->create([
        'name' => 'gateway-4',
        'host' => 'gateway-4.internal',
        'gateway_endpoint' => null,
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $activeGateway->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $inactiveGateway->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $existingVpnGateway->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $existingVpnGateway->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => [
            'public_endpoint' => 'existing.example.com',
            'wireguard_cidr' => '10.6.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.6.0.1',
        ],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $hostFallbackGateway->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    runGatewayCoupledVpnRoleBackfillMigration();

    expect(DB::table('node_role')->where([
        'node_id' => $activeGateway->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => json_encode([
            'public_endpoint' => 'gateway.example.com',
            'wireguard_cidr' => '10.6.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.6.0.1',
        ], JSON_THROW_ON_ERROR),
    ])->exists())->toBeTrue();

    expect(DB::table('node_role')->where([
        'node_id' => $inactiveGateway->id,
        'role' => 'vpn',
    ])->exists())->toBeFalse();

    expect(DB::table('node_role')->where([
        'node_id' => $existingVpnGateway->id,
        'role' => 'vpn',
    ])->count())->toBe(1);

    expect(DB::table('node_role')->where([
        'node_id' => $hostFallbackGateway->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => json_encode([
            'public_endpoint' => 'gateway-4.internal',
            'wireguard_cidr' => '10.6.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.6.0.1',
        ], JSON_THROW_ON_ERROR),
    ])->exists())->toBeTrue();
});

it('preserves vpn role assignments on rollback', function (): void {
    $gateway = Node::factory()->create([
        'name' => 'gateway-1',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => [
            'public_endpoint' => 'gateway.example.com',
            'wireguard_cidr' => '10.6.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.6.0.1',
        ],
    ]);

    rollbackGatewayCoupledVpnRoleBackfillMigration();

    expect(DB::table('node_role')->where([
        'node_id' => $gateway->id,
        'role' => 'vpn',
    ])->exists())->toBeTrue();
});
