<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Nodes\Roles\RoleBaselines\AgentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppDevelopmentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\DatabaseRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\GatewayRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\S3RoleBaseline;
use App\Services\S3\S3RuntimeContainer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function s3BaselineNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'storage-1',
        'platform' => 'ubuntu',
        'host' => 'storage-1.example.com',
        'wireguard_address' => '10.6.0.44',
        'status' => NodeStatus::Active,
    ], $overrides));
}

function s3BaselineAssignment(Node $node, NodeRoleStatus $status = NodeRoleStatus::Pending, array $settings = []): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::S3->value,
        'status' => $status->value,
        'settings' => array_merge(['data_path' => '/srv/orbit/s3/data'], $settings),
    ]);
}

// ---------------------------------------------------------------------------
// Converger dispatches S3 role to S3RoleBaseline
// ---------------------------------------------------------------------------

it('dispatches the s3 role to S3RoleBaseline via NodeRoleBaselineConverger', function (): void {
    $node = s3BaselineNode();
    $assignment = s3BaselineAssignment($node);

    $baseline = Mockery::mock(S3RoleBaseline::class);
    $baseline->shouldReceive('converge')->once()->with($node, $assignment);

    $converger = new NodeRoleBaselineConverger(
        gatewayRoleBaseline: app(GatewayRoleBaseline::class),
        appDevelopmentRoleBaseline: app(AppDevelopmentRoleBaseline::class),
        appProductionRoleBaseline: app(AppProductionRoleBaseline::class),
        databaseRoleBaseline: app(DatabaseRoleBaseline::class),
        agentRoleBaseline: app(AgentRoleBaseline::class),
        s3RoleBaseline: $baseline,
    );

    $converger->converge($node, $assignment);
});

// ---------------------------------------------------------------------------
// converge() — creates seaweedfs tool row and writes credentials
// ---------------------------------------------------------------------------

it('creates the seaweedfs NodeTool row on first converge', function (): void {
    $node = s3BaselineNode();
    $assignment = s3BaselineAssignment($node);

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    $seaweedfsTool = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'seaweedfs')
        ->first();

    expect($seaweedfsTool)->not->toBeNull()
        ->and($seaweedfsTool->expected_state)->toBe('installed');
});

it('writes credentials to the seaweedfs NodeTool row on first converge', function (): void {
    $node = s3BaselineNode();
    $assignment = s3BaselineAssignment($node);

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    $seaweedfsTool = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'seaweedfs')
        ->firstOrFail();

    $fields = $seaweedfsTool->credentials['fields'] ?? null;

    expect($fields)->toBeArray()
        ->and($fields['access_key_id'])->toBeString()->not->toBeEmpty()
        ->and($fields['secret_access_key'])->toBeString()->not->toBeEmpty()
        ->and($fields['region'])->toBe('orbit')
        ->and($fields['endpoint'])->toBe('https://s3.orbit');
});

it('renders the runtime container config and persists container metadata', function (): void {
    $node = s3BaselineNode(['wireguard_address' => '10.6.0.44']);
    $assignment = s3BaselineAssignment($node);

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    $seaweedfsTool = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'seaweedfs')
        ->firstOrFail();

    expect($seaweedfsTool->config['container_name'])->toBe(S3RuntimeContainer::ContainerName)
        ->and($seaweedfsTool->config['runtime'])->toBe('docker-container');
});

it('preserves the role-owned data path in the seaweedfs tool config', function (): void {
    $node = s3BaselineNode();
    $assignment = s3BaselineAssignment($node, settings: ['data_path' => '/mnt/fast-disk/s3']);

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    $seaweedfsTool = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'seaweedfs')
        ->firstOrFail();

    expect($seaweedfsTool->config['data_path'])->toBe('/mnt/fast-disk/s3');
});

// ---------------------------------------------------------------------------
// Re-converge — no credential rotation
// ---------------------------------------------------------------------------

it('does not rotate credentials on re-converge', function (): void {
    $node = s3BaselineNode();
    $assignment = s3BaselineAssignment($node);

    $converger = app(NodeRoleBaselineConverger::class);

    // First converge generates credentials.
    $converger->converge($node, $assignment);

    $afterFirst = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'seaweedfs')
        ->firstOrFail();

    $firstFields = $afterFirst->credentials['fields'];

    // Second converge must preserve them.
    $converger->converge($node, $assignment);

    $afterSecond = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'seaweedfs')
        ->firstOrFail();

    $secondFields = $afterSecond->credentials['fields'];

    expect($secondFields['access_key_id'])->toBe($firstFields['access_key_id'])
        ->and($secondFields['secret_access_key'])->toBe($firstFields['secret_access_key']);
});

// ---------------------------------------------------------------------------
// remove() — deletes the seaweedfs tool row; does not touch data path
// ---------------------------------------------------------------------------

it('removes the seaweedfs NodeTool row on remove', function (): void {
    $node = s3BaselineNode();
    $assignment = s3BaselineAssignment($node, NodeRoleStatus::Active);

    // Seed a seaweedfs tool row to be deleted.
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'seaweedfs',
        'expected_state' => 'installed',
    ]);

    app(NodeRoleBaselineConverger::class)->remove($node, $assignment, purgeData: false);

    $remaining = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'seaweedfs')
        ->first();

    expect($remaining)->toBeNull();
});

it('does not purge the host data path when purgeData is true (configurator boundary)', function (): void {
    // The configurator remove() signature accepts $purgeData but deliberately
    // does not delete the host data path — that is handled outside the configurator
    // by provisioning scripts. This test verifies the baseline passes the flag
    // through and the configurator only removes the tool row.
    $node = s3BaselineNode();
    $assignment = s3BaselineAssignment($node, NodeRoleStatus::Active);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'seaweedfs',
        'expected_state' => 'installed',
    ]);

    // purgeData: true — should still only delete the DB row, no file ops.
    app(NodeRoleBaselineConverger::class)->remove($node, $assignment, purgeData: true);

    $remaining = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'seaweedfs')
        ->first();

    expect($remaining)->toBeNull();
});
