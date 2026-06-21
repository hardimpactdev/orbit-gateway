<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\Roles\RoleBaselines\AppDevelopmentRoleBaseline;
use App\Services\Runtime\OrbitCaddyContainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->configDir = storage_path('framework/testing/app-dev-baseline-dns');
    File::deleteDirectory($this->configDir);
});

afterEach(function (): void {
    File::deleteDirectory($this->configDir);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function appDevBaselineNode(array $attributes = []): Node
{
    return Node::factory()->create([
        'platform' => 'ubuntu',
        'host' => '10.6.0.20',
        'wireguard_address' => '10.6.0.20',
        'status' => NodeStatus::Active,
        ...$attributes,
    ]);
}

function appDevBaselineAssignment(Node $node): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => NodeRoleName::AppDevelopment->value,
        'status' => NodeRoleStatus::Active->value,
        'settings' => ['tld' => 'test'],
    ]);
}

describe('AppDevelopmentRoleBaseline host toolchain', function (): void {
    it('converges php-cli with expected_state installed', function (): void {
        $node = appDevBaselineNode();
        $assignment = appDevBaselineAssignment($node);

        $baseline = new AppDevelopmentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'php-cli')
            ->first();

        expect($tool)->not->toBeNull()
            ->and($tool->expected_state)->toBe('installed');
    });

    it('converges composer with expected_state installed', function (): void {
        $node = appDevBaselineNode();
        $assignment = appDevBaselineAssignment($node);

        $baseline = new AppDevelopmentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'composer')
            ->first();

        expect($tool)->not->toBeNull()
            ->and($tool->expected_state)->toBe('installed');
    });

    it('converges gh with expected_state installed', function (): void {
        $node = appDevBaselineNode();
        $assignment = appDevBaselineAssignment($node);

        $baseline = new AppDevelopmentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'gh')
            ->first();

        expect($tool)->not->toBeNull()
            ->and($tool->expected_state)->toBe('installed');
    });

    it('converges laravel-installer with expected_state installed', function (): void {
        $node = appDevBaselineNode();
        $assignment = appDevBaselineAssignment($node);

        $baseline = new AppDevelopmentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'laravel-installer')
            ->first();

        expect($tool)->not->toBeNull()
            ->and($tool->expected_state)->toBe('installed');
    });

    it('does not converge supervisor as a supported tool', function (): void {
        $node = appDevBaselineNode();
        $assignment = appDevBaselineAssignment($node);

        $baseline = new AppDevelopmentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'supervisor')
            ->first();

        expect($tool)->toBeNull();
    });

    it('does not converge the legacy php runtime tool row', function (): void {
        $node = appDevBaselineNode();
        $assignment = appDevBaselineAssignment($node);

        $baseline = new AppDevelopmentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        expect(NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'php')
            ->exists())->toBeFalse();
    });

    it('publishes app-dev caddy HTTP listeners on the node public IPv4 when present', function (): void {
        $node = appDevBaselineNode([
            'public_ipv4' => '192.168.1.150',
        ]);
        $assignment = appDevBaselineAssignment($node);

        $baseline = new AppDevelopmentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'caddy')
            ->firstOrFail();

        expect($tool->config['container']['published_ports'])->toBe([
            '10.6.0.20:80:80',
            '10.6.0.20:443:443',
            '10.6.0.20:443:443/udp',
            '192.168.1.150:80:80',
            '192.168.1.150:443:443',
            '192.168.1.150:443:443/udp',
            '10.6.0.20:'.OrbitCaddyContainer::PrivateBackendPort.':'.OrbitCaddyContainer::PrivateBackendPort,
        ]);
    });

    it('removes host toolchain rows on role removal', function (): void {
        $node = appDevBaselineNode();
        $assignment = appDevBaselineAssignment($node);

        $baseline = new AppDevelopmentRoleBaseline(
            new DevelopmentDnsMappingEnactor($this->configDir),
        );

        $baseline->converge($node, $assignment);

        expect(NodeTool::query()->where('node_id', $node->id)->whereIn('name', ['php-cli', 'composer', 'laravel-installer', 'gh'])->count())->toBe(4);

        $baseline->remove($node, $assignment, purgeData: false);

        expect(NodeTool::query()->where('node_id', $node->id)->whereIn('name', ['php-cli', 'composer', 'laravel-installer', 'gh'])->count())->toBe(0);
    });
});
