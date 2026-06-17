<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Updates\UpdateApplyResult;
use App\Services\Updates\UpdateDriver;
use App\Services\Updates\UpdateDriverRegistry;
use App\Services\Updates\UpdateDriverTarget;
use App\Services\Updates\UpdatePostureSnapshot;
use App\Services\Updates\UpdateTarget;
use App\Services\Updates\UpdateTargetFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('UpdateDriverRegistry', function (): void {
    it('selects a matching driver for a managed Ubuntu server node target', function (): void {
        $target = new UpdateTarget(
            family: 'node',
            node: Node::factory()->make(['platform' => 'ubuntu_24-04']),
            platform: 'ubuntu_24-04',
            scope: 'managed-server-node',
        );

        $registry = new UpdateDriverRegistry([
            new RegistryFakeUpdateDriver,
        ]);

        $drivers = $registry->driversFor($target);

        expect($drivers)
            ->toHaveCount(1)
            ->and($drivers[0]->key())->toBe('fake-updates');
    });

    it('selects no drivers for an unsupported macOS node target', function (): void {
        $target = new UpdateTarget(
            family: 'node',
            node: Node::factory()->make(['platform' => 'macos_15']),
            platform: 'macos',
            scope: 'unsupported-node',
        );

        $registry = new UpdateDriverRegistry([
            new RegistryFakeUpdateDriver,
        ]);

        expect($registry->driversFor($target))->toBe([]);
    });

    it('returns an empty list without manufacturing an issue when no drivers match', function (): void {
        $target = new UpdateTarget(
            family: 'node',
            node: Node::factory()->make(['platform' => 'ubuntu_24-04']),
            platform: 'ubuntu_24-04',
            scope: 'unsupported-node',
        );

        $snapshot = new UpdatePostureSnapshot('fake-updates', []);
        $registry = new UpdateDriverRegistry([
            new RegistryFakeUpdateDriver,
        ]);

        expect($registry->driversFor($target))
            ->toBe([])
            ->and($snapshot->issues)->toBe([]);
    });

    it('builds a managed target for active Ubuntu server-role nodes', function (): void {
        $node = Node::factory()->create([
            'platform' => 'ubuntu_24-04',
            'status' => NodeStatus::Active,
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-prod',
            'status' => 'active',
        ]);

        $target = app(UpdateTargetFactory::class)->forNode($node->refresh());

        expect($target->family)->toBe('node')
            ->and($target->node->is($node))->toBeTrue()
            ->and($target->platform)->toBe('ubuntu_24-04')
            ->and($target->scope)->toBe('managed-server-node');
    });

    it('builds an unsupported target for macOS control nodes', function (): void {
        $node = Node::factory()->create([
            'platform' => 'macos_15',
            'status' => NodeStatus::Active,
        ]);

        $target = app(UpdateTargetFactory::class)->forNode($node);

        expect($target->family)->toBe('node')
            ->and($target->node->is($node))->toBeTrue()
            ->and($target->platform)->toBe('macos_15')
            ->and($target->scope)->toBe('unsupported-node');
    });
});

final class RegistryFakeUpdateDriver implements UpdateDriver
{
    public function key(): string
    {
        return 'fake-updates';
    }

    public function supportedTargets(): array
    {
        return [new UpdateDriverTarget('node', 'ubuntu_24-04', 'managed-server-node')];
    }

    public function supports(UpdateTarget $target): bool
    {
        return $target->family === 'node'
            && $target->platform === 'ubuntu_24-04'
            && $target->scope === 'managed-server-node';
    }

    public function probe(UpdateTarget $target): UpdatePostureSnapshot
    {
        return new UpdatePostureSnapshot($this->key(), []);
    }

    public function apply(UpdateTarget $target): UpdateApplyResult
    {
        return new UpdateApplyResult($this->key(), 'completed', 'Applied fake updates.');
    }
}
