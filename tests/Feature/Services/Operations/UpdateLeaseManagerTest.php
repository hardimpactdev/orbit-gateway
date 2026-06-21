<?php

declare(strict_types=1);

use App\Exceptions\UpdateLeaseConflict;
use App\Models\OperationRun;
use App\Models\UpdateLease;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\UpdateLeaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->recorder = app(OperationRunRecorder::class);
    $this->manager = app(UpdateLeaseManager::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('acquires active leases for update resources', function (string $resourceType, string $resourceKey): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $run = updateLeaseOperationRun();

    $lease = $this->manager->acquire(
        resourceType: $resourceType,
        resourceKey: $resourceKey,
        operationRun: $run,
        ownerToken: 'runner-token',
        ttlSeconds: 120,
    );

    expect($lease)->toBeInstanceOf(UpdateLease::class)
        ->and($lease->resource_type)->toBe($resourceType)
        ->and($lease->resource_key)->toBe($resourceKey)
        ->and($lease->active_resource_key)->toBe("{$resourceType}:{$resourceKey}")
        ->and($lease->operation_run_id)->toBe($run->id)
        ->and($lease->owner_token)->toBe('runner-token')
        ->and($lease->expires_at?->toIso8601String())->toBe('2026-06-02T10:02:00+00:00')
        ->and(UpdateLease::query()->where('active_resource_key', "{$resourceType}:{$resourceKey}")->count())->toBe(1);
})->with([
    'fleet' => ['fleet', 'update-all'],
    'gateway' => ['gateway', 'orbit-gateway'],
    'scheduler' => ['scheduler', 'orbit-scheduler'],
    'node' => ['node', 'worker-01'],
]);

it('throws a typed conflict with active owner metadata', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $firstRun = updateLeaseOperationRun();
    $secondRun = updateLeaseOperationRun();

    $this->manager->acquire('gateway', 'orbit-gateway', $firstRun, 'first-owner', 300);

    try {
        $this->manager->acquire('gateway', 'orbit-gateway', $secondRun, 'second-owner', 300);
    } catch (UpdateLeaseConflict $exception) {
        expect($exception->resourceType)->toBe('gateway')
            ->and($exception->resourceKey)->toBe('orbit-gateway')
            ->and($exception->operationRunId)->toBe($firstRun->id)
            ->and($exception->ownerToken)->toBe('first-owner')
            ->and($exception->expiresAt->toIso8601String())->toBe('2026-06-02T10:05:00+00:00')
            ->and($exception->context())->toMatchArray([
                'resource_type' => 'gateway',
                'resource_key' => 'orbit-gateway',
                'operation_run_id' => $firstRun->id,
                'owner_token' => 'first-owner',
                'expires_at' => '2026-06-02T10:05:00+00:00',
            ]);

        return;
    }

    $this->fail('Expected an update lease conflict.');
});

it('reclaims expired leases before acquiring the resource again', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $staleRun = updateLeaseOperationRun();
    $freshRun = updateLeaseOperationRun();
    $stale = $this->manager->acquire('fleet', 'update-all', $staleRun, 'stale-owner', 30);

    Carbon::setTestNow('2026-06-02 10:00:31');

    $fresh = $this->manager->acquire('fleet', 'update-all', $freshRun, 'fresh-owner', 300);

    expect($stale->refresh()->active_resource_key)->toBeNull()
        ->and($stale->released_at?->toIso8601String())->toBe('2026-06-02T10:00:31+00:00')
        ->and($fresh->id)->not->toBe($stale->id)
        ->and($fresh->active_resource_key)->toBe('fleet:update-all')
        ->and($fresh->operation_run_id)->toBe($freshRun->id);
});

it('releases an active lease and allows a later acquire', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $firstRun = updateLeaseOperationRun();
    $secondRun = updateLeaseOperationRun();
    $lease = $this->manager->acquire('node', 'worker-01', $firstRun, 'node-owner', 300);

    $released = $this->manager->release($lease, 'node-owner');

    $reacquired = $this->manager->acquire('node', 'worker-01', $secondRun, 'new-owner', 300);

    expect($released->active_resource_key)->toBeNull()
        ->and($released->released_at?->toIso8601String())->toBe('2026-06-02T10:00:00+00:00')
        ->and($reacquired->id)->not->toBe($lease->id)
        ->and($reacquired->active_resource_key)->toBe('node:worker-01')
        ->and($reacquired->owner_token)->toBe('new-owner');
});

it('maps insert-time unique constraint races to update lease conflicts', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $run = updateLeaseOperationRun();
    $competingRun = updateLeaseOperationRun();
    $manager = new class($competingRun) extends UpdateLeaseManager
    {
        private bool $injected = false;

        public function __construct(private OperationRun $competingRun) {}

        protected function beforeActiveLeaseCreate(string $activeResourceKey, string $resourceType, string $resourceKey): void
        {
            if ($this->injected) {
                return;
            }

            $this->injected = true;

            UpdateLease::query()->create([
                'resource_type' => $resourceType,
                'resource_key' => $resourceKey,
                'active_resource_key' => $activeResourceKey,
                'operation_run_id' => $this->competingRun->id,
                'owner_token' => 'race-owner',
                'expires_at' => Carbon::now()->addMinutes(5),
            ]);
        }
    };

    try {
        $manager->acquire('scheduler', 'orbit-scheduler', $run, 'runner-owner', 300);
    } catch (UpdateLeaseConflict $exception) {
        expect($exception->resourceType)->toBe('scheduler')
            ->and($exception->resourceKey)->toBe('orbit-scheduler')
            ->and($exception->operationRunId)->toBe($competingRun->id)
            ->and($exception->ownerToken)->toBe('race-owner');

        return;
    }

    $this->fail('Expected a typed update lease conflict for the insert race.');
});

it('extends active leases with a heartbeat and expires them when heartbeats stop', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $run = updateLeaseOperationRun();
    $nextRun = updateLeaseOperationRun();
    $lease = $this->manager->acquire('node', 'worker-01', $run, 'runner-owner', 30);

    Carbon::setTestNow('2026-06-02 10:00:20');

    $heartbeat = $this->manager->heartbeat($lease, 'runner-owner', 30);

    expect($heartbeat->expires_at?->toIso8601String())->toBe('2026-06-02T10:00:50+00:00');

    Carbon::setTestNow('2026-06-02 10:00:49');

    expect(fn () => $this->manager->acquire('node', 'worker-01', $nextRun, 'new-owner', 300))
        ->toThrow(UpdateLeaseConflict::class);

    Carbon::setTestNow('2026-06-02 10:00:51');

    $fresh = $this->manager->acquire('node', 'worker-01', $nextRun, 'new-owner', 300);

    expect($fresh->owner_token)->toBe('new-owner')
        ->and($lease->refresh()->active_resource_key)->toBeNull()
        ->and($lease->released_at?->toIso8601String())->toBe('2026-06-02T10:00:51+00:00');
});

it('does not revive an already expired lease on heartbeat', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $run = updateLeaseOperationRun();
    $lease = $this->manager->acquire('gateway', 'orbit-gateway', $run, 'gateway-owner', 30);

    Carbon::setTestNow('2026-06-02 10:00:31');

    expect(fn () => $this->manager->heartbeat($lease, 'gateway-owner', 300))
        ->toThrow(RuntimeException::class, 'expired');

    expect($lease->refresh()->active_resource_key)->toBeNull()
        ->and($lease->released_at?->toIso8601String())->toBe('2026-06-02T10:00:31+00:00');
});

it('releases stage leases in finally paths', function (): void {
    Carbon::setTestNow('2026-06-02 10:00:00');

    $run = updateLeaseOperationRun();

    expect(fn () => $this->manager->withLease(
        resourceType: 'scheduler',
        resourceKey: 'orbit-scheduler',
        operationRun: $run,
        ownerToken: 'stage-owner',
        ttlSeconds: 300,
        callback: function (UpdateLease $lease): void {
            expect($lease->active_resource_key)->toBe('scheduler:orbit-scheduler');

            throw new RuntimeException('stage failed');
        },
    ))->toThrow(RuntimeException::class, 'stage failed');

    $released = UpdateLease::query()->where('resource_type', 'scheduler')->firstOrFail();

    expect($released->active_resource_key)->toBeNull()
        ->and($released->released_at?->toIso8601String())->toBe('2026-06-02T10:00:00+00:00');
});

function updateLeaseOperationRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}
