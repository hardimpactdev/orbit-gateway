<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('node role assignments', function (): void {
    it('discovers active app host nodes from composable roles instead of unassigned nodes', function (): void {
        $developmentNode = Node::factory()->create(['status' => 'active']);
        $productionNode = Node::factory()->create(['status' => 'active']);
        $unassignedAppNode = Node::factory()->create(['status' => 'active']);
        $pendingAppNode = Node::factory()->create(['status' => 'active']);
        $databaseNode = Node::factory()->create(['status' => 'active']);
        $agentNode = Node::factory()->create(['status' => 'active']);
        $metricsNode = Node::factory()->create(['status' => 'active']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $developmentNode->id,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $productionNode->id,
            'role' => 'app-prod',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $pendingAppNode->id,
            'role' => 'app-dev',
            'status' => 'pending',
            'settings' => ['tld' => 'test'],
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $databaseNode->id,
            'role' => 'database',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $agentNode->id,
            'role' => 'agent',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $metricsNode->id,
            'role' => 'metrics',
            'status' => 'active',
        ]);

        $assignments = app(NodeRoleAssignments::class);

        expect($assignments->activeAppHostNodeIds())->toBe([
            $developmentNode->id,
            $productionNode->id,
        ])
            ->and($assignments->activeToolHostNodeIds())->toBe([
                $developmentNode->id,
                $productionNode->id,
                $databaseNode->id,
                $agentNode->id,
                $metricsNode->id,
            ])
            ->and($assignments->nodeHasActiveAppHostRole($developmentNode))->toBeTrue()
            ->and($assignments->nodeHasActiveAppHostRole($productionNode))->toBeTrue()
            ->and($assignments->nodeHasActiveAppHostRole($unassignedAppNode))->toBeFalse()
            ->and($assignments->nodeHasActiveAppHostRole($pendingAppNode))->toBeFalse()
            ->and($assignments->nodeHasActiveAppHostRole($databaseNode))->toBeFalse()
            ->and($assignments->nodeHasActiveToolHostRole($databaseNode))->toBeTrue()
            ->and($assignments->nodeHasActiveToolHostRole($agentNode))->toBeTrue()
            ->and($assignments->nodeHasActiveToolHostRole($metricsNode))->toBeTrue()
            ->and($assignments->activeAppHostEnvironment($developmentNode))->toBe('development')
            ->and($assignments->activeAppHostEnvironment($productionNode))->toBe('production')
            ->and($assignments->activeAppHostEnvironment($unassignedAppNode))->toBeNull();
    });

    it('only treats active nodes with active gateway assignments as gateways', function (): void {
        $activeUnassignedGateway = Node::factory()->create(['status' => 'active']);
        $inactiveUnassignedGateway = Node::factory()->create(['status' => 'provisioning']);
        $activeAssignedGateway = Node::factory()->create(['status' => 'active']);
        $inactiveAssignedGateway = Node::factory()->create(['status' => 'provisioning']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $activeAssignedGateway->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $inactiveAssignedGateway->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);

        $assignments = app(NodeRoleAssignments::class);

        expect($assignments->nodeIsGateway($activeUnassignedGateway))->toBeFalse()
            ->and($assignments->nodeIsGateway($inactiveUnassignedGateway))->toBeFalse()
            ->and($assignments->nodeIsGateway($activeAssignedGateway))->toBeTrue()
            ->and($assignments->nodeIsGateway($inactiveAssignedGateway))->toBeFalse();
    });

    it('discovers the active vpn node from role assignments', function (): void {
        $activeVpnNode = Node::factory()->create(['status' => 'active']);
        $inactiveVpnNode = Node::factory()->create(['status' => 'provisioning']);
        $pendingVpnNode = Node::factory()->create(['status' => 'active']);
        $unassignedVpnNode = Node::factory()->create(['status' => 'active']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $activeVpnNode->id,
            'role' => 'vpn',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $inactiveVpnNode->id,
            'role' => 'vpn',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $pendingVpnNode->id,
            'role' => 'vpn',
            'status' => 'pending',
        ]);

        $assignments = app(NodeRoleAssignments::class);

        expect($assignments->nodeHasActiveVpnRole($activeVpnNode))->toBeTrue()
            ->and($assignments->nodeHasActiveVpnRole($inactiveVpnNode))->toBeTrue()
            ->and($assignments->nodeHasActiveVpnRole($pendingVpnNode))->toBeFalse()
            ->and($assignments->nodeHasActiveVpnRole($unassignedVpnNode))->toBeFalse()
            ->and($assignments->activeVpnNodeQuery()->pluck('id')->all())->toBe([$activeVpnNode->id]);
    });

    it('labels effective roles from active assignments', function (): void {
        $gateway = Node::factory()->create([]);
        $development = Node::factory()->create([]);
        $database = Node::factory()->create([]);
        $metrics = Node::factory()->create([]);
        $control = Node::factory()->create([]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $development->id,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $database->id,
            'role' => 'database',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $metrics->id,
            'role' => 'metrics',
            'status' => 'active',
        ]);

        $assignments = app(NodeRoleAssignments::class);

        expect($assignments->assignmentRoleLabel($gateway))->toBe('gateway')
            ->and($assignments->assignmentRoleLabel($development))->toBe('app-dev')
            ->and($assignments->assignmentRoleLabel($database))->toBe('database')
            ->and($assignments->assignmentRoleLabel($metrics))->toBe('metrics')
            ->and($assignments->assignmentRoleLabel($control))->toBe('operator');
    });
});
