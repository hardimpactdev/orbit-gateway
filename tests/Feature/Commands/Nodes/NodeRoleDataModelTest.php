<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('uses the singular node role table on a fresh schema', function (): void {
    expect(Schema::hasTable('node_role'))->toBeTrue()
        ->and(Schema::hasTable('node_roles'))->toBeFalse()
        ->and((new NodeRoleAssignment)->getTable())->toBe('node_role');
});

it('stores multiple role assignments with typed status and settings per node', function (): void {
    $node = Node::factory()->create([
        'name' => 'dev-1',
    ]);

    NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => 'active',
        'settings' => [],
    ]);

    expect($node->fresh()->roleAssignments)
        ->toHaveCount(2)
        ->and($node->fresh()->roleAssignments->pluck('role')->all())
        ->toBe(['app-dev', 'database'])
        ->and(DB::table('node_role')->where([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => 'active',
        ])->exists())->toBeTrue();
});

it('enforces one assignment per role per node', function (): void {
    $node = Node::factory()->create();

    NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => 'active',
        'settings' => [],
    ]);

    expect(fn () => NodeRoleAssignment::query()->create([
        'node_id' => $node->id,
        'role' => 'database',
        'status' => 'active',
        'settings' => [],
    ]))->toThrow(QueryException::class);
});
