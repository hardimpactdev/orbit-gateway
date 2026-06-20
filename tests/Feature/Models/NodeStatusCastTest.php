<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts node statuses to enums while storing database values', function (): void {
    $node = Node::factory()->create([
        'status' => NodeStatus::Provisioning,
    ]);

    expect($node->status)
        ->toBe(NodeStatus::Provisioning)
        ->and($node->getRawOriginal('status'))
        ->toBe(NodeStatus::Provisioning->value);

    $node->forceFill(['status' => NodeStatus::Active])->save();

    expect($node->refresh()->status)
        ->toBe(NodeStatus::Active)
        ->and($node->getRawOriginal('status'))
        ->toBe(NodeStatus::Active->value);
});

it('casts node role assignment statuses to enums while storing database values', function (): void {
    $assignment = NodeRoleAssignment::factory()->create([
        'status' => NodeRoleStatus::Pending,
    ]);

    expect($assignment->status)
        ->toBe(NodeRoleStatus::Pending)
        ->and($assignment->getRawOriginal('status'))
        ->toBe(NodeRoleStatus::Pending->value);

    $assignment->forceFill(['status' => NodeRoleStatus::Active])->save();

    expect($assignment->refresh()->status)
        ->toBe(NodeRoleStatus::Active)
        ->and($assignment->getRawOriginal('status'))
        ->toBe(NodeRoleStatus::Active->value);
});
