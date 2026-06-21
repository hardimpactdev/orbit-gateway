<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\ScheduleLock;
use App\Models\SchedulerState;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores the gateway scheduler heartbeat state', function (): void {
    $node = Node::factory()->gateway()->create(['name' => 'gateway']);

    $state = SchedulerState::factory()->create([
        'node_id' => $node->id,
        'heartbeat_at' => '2026-05-06 12:34:00',
        'registry_synced_at' => '2026-05-06 12:33:55',
    ]);

    expect($node->schedulerState->is($state))->toBeTrue()
        ->and($state->node->is($node))->toBeTrue()
        ->and($state->heartbeat_at?->toIso8601String())->toBe('2026-05-06T12:34:00+00:00')
        ->and($state->registry_synced_at?->toIso8601String())->toBe('2026-05-06T12:33:55+00:00');
});

it('keeps scheduler state unique per node', function (): void {
    $node = Node::factory()->create();

    SchedulerState::factory()->create(['node_id' => $node->id]);

    expect(fn () => SchedulerState::factory()->create(['node_id' => $node->id]))
        ->toThrow(QueryException::class);
});

it('stores gateway schedule locks by stable schedule key', function (): void {
    $gateway = Node::factory()->gateway()->create(['name' => 'gateway']);

    $firstLock = ScheduleLock::factory()->create([
        'node_id' => $gateway->id,
        'schedule_key' => 'app:docs:laravel-scheduler',
        'owner_token' => 'tick-1',
        'locked_at' => '2026-05-06 12:34:00',
        'expires_at' => '2026-05-06 12:39:00',
    ]);

    expect($gateway->scheduleLocks()->first()->is($firstLock))->toBeTrue()
        ->and($firstLock->node->is($gateway))->toBeTrue()
        ->and($firstLock->locked_at->toIso8601String())->toBe('2026-05-06T12:34:00+00:00')
        ->and($firstLock->expires_at?->toIso8601String())->toBe('2026-05-06T12:39:00+00:00');
});

it('keeps schedule lock keys unique on the gateway', function (): void {
    $node = Node::factory()->gateway()->create(['name' => 'gateway']);

    ScheduleLock::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'node:app-1:backups',
    ]);

    expect(fn () => ScheduleLock::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'node:app-1:backups',
    ]))->toThrow(QueryException::class);
});
