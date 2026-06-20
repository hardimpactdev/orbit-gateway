<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function appProdBaselineNode(): Node
{
    return Node::factory()->create([
        'platform' => 'ubuntu',
        'host' => '10.6.0.30',
        'wireguard_address' => '10.6.0.30',
        'status' => NodeStatus::Active,
    ]);
}

function appProdBaselineAssignment(Node $node): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => NodeRoleName::AppProduction->value,
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);
}

describe('AppProductionRoleBaseline host toolchain', function (): void {
    it('converges php-cli with expected_state installed', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'php-cli')
            ->first();

        expect($tool)->not->toBeNull()
            ->and($tool->expected_state)->toBe('installed');
    });

    it('converges composer with expected_state installed', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'composer')
            ->first();

        expect($tool)->not->toBeNull()
            ->and($tool->expected_state)->toBe('installed');
    });

    it('converges gh with expected_state installed', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'gh')
            ->first();

        expect($tool)->not->toBeNull()
            ->and($tool->expected_state)->toBe('installed');
    });

    it('converges laravel-installer with expected_state installed', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'laravel-installer')
            ->first();

        expect($tool)->not->toBeNull()
            ->and($tool->expected_state)->toBe('installed');
    });

    it('does not converge the legacy php runtime tool row', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        expect(NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'php')
            ->exists())->toBeFalse();
    });

    it('removes host toolchain rows on role removal', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        $baseline = new AppProductionRoleBaseline;

        $baseline->converge($node, $assignment);

        expect(NodeTool::query()->where('node_id', $node->id)->whereIn('name', ['php-cli', 'composer', 'laravel-installer', 'gh'])->count())->toBe(4);

        $baseline->remove($node, $assignment, purgeData: false);

        expect(NodeTool::query()->where('node_id', $node->id)->whereIn('name', ['php-cli', 'composer', 'laravel-installer', 'gh'])->count())->toBe(0);
    });

    it('rejects convergence on gateway nodes', function (): void {
        $node = appProdBaselineNode();
        $assignment = appProdBaselineAssignment($node);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => NodeRoleName::Gateway->value,
            'status' => NodeRoleStatus::Active->value,
        ]);

        $baseline = new AppProductionRoleBaseline;

        expect(fn () => $baseline->converge($node, $assignment))
            ->toThrow(RuntimeException::class, 'The app-prod role cannot be assigned to a gateway node.');
    });
});
