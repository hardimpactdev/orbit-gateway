<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes operator terminology for nodes without workload role assignments', function (): void {
    $node = Node::factory()->operator()->create([
        'name' => 'control-1',
    ]);

    expect($node->isOperator())->toBeTrue()
        ->and($node->displayRole())->toBe('operator');
});

it('uses the primary active role assignment in the display label', function (): void {
    $node = Node::factory()->create();

    $node->roleAssignments()->create([
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    expect($node->fresh()->isOperator())->toBeFalse()
        ->and($node->fresh()->displayRole())->toBe('gateway');
});
