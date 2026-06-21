<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Schedules\OrbitScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('runs a gateway scheduler tick without due schedules', function (): void {
    createOrbitSchedulerUnitGatewayNode();

    $startedAt = CarbonImmutable::parse('2026-05-06 12:34:00', 'UTC');

    $result = app(OrbitScheduler::class)->tick($startedAt);

    expect($result->startedAt->equalTo($startedAt))->toBeTrue()
        ->and($result->dueSchedules)->toBe(0)
        ->and($result->executedSchedules)->toBe(0)
        ->and($result->finishedAt)->toBeInstanceOf(CarbonImmutable::class);
});

it('aligns daemon sleeps to the next wall-clock minute', function (): void {
    $scheduler = app(OrbitScheduler::class);

    expect($scheduler->secondsUntilNextMinute(CarbonImmutable::parse('2026-05-06 12:34:45', 'UTC')))->toBe(15)
        ->and($scheduler->secondsUntilNextMinute(CarbonImmutable::parse('2026-05-06 12:34:00', 'UTC')))->toBe(60);
});

function createOrbitSchedulerUnitGatewayNode(): Node
{
    $node = Node::factory()->create([
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $node;
}
