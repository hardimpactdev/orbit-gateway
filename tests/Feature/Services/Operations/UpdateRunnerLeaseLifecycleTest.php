<?php

declare(strict_types=1);

use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Exceptions\UpdateLeaseConflict;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Models\UpdateLease;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdateLeaseManager;
use App\Services\Operations\UpdateRunner;
use App\Services\Operations\UpdateRunnerPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

it('holds the fleet lease across gateway workload and verification phases', function (): void {
    $collector = new UpdateRunnerLeaseCollector;
    $run = updateRunnerLeaseRun();

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerLeaseSnapshot());
    app()->instance(UpdateRunnerPipeline::class, new RecordingUpdateRunnerPipeline($collector));

    app(UpdateRunner::class)->run($run->id);

    expect($collector->records)->toBe([
        ['phase' => 'gateway', 'active' => ['fleet:update-all', 'gateway:orbit-gateway', 'scheduler:orbit-scheduler']],
        ['phase' => 'workloads', 'active' => ['fleet:update-all']],
        ['phase' => 'verification', 'active' => ['fleet:update-all']],
    ])
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())->toBe(0)
        ->and($run->refresh()->status)->toBe(OperationStatus::Succeeded);
});

it('reports the conflicting operation caller node when the fleet lease is already held', function (): void {
    config()->set('app.version', '1.0.0');

    $run = updateRunnerLeaseRun();
    $caller = Node::factory()->create(['name' => 'ingress']);
    $otherRun = updateRunnerLeaseRun(callerNodeId: $caller->id);

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerLeaseSnapshot());
    $conflictingLease = app(UpdateLeaseManager::class)->acquire(
        resourceType: 'fleet',
        resourceKey: 'update-all',
        operationRun: $otherRun,
        ownerToken: 'other-owner',
        ttlSeconds: 300,
    );

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(UpdateLeaseConflict::class);

    $error = $run->events()
        ->where('event_type', 'error')
        ->firstOrFail();
    $eventMarkers = $run->events()
        ->get()
        ->map(fn ($event): string => $event->event_type === 'step'
            ? "{$event->payload['key']}:{$event->payload['status']}"
            : $event->event_type)
        ->all();

    expect($run->refresh()->error)->toMatchArray([
        'code' => 'update_lease_conflict',
        'data' => [
            'resource' => 'fleet:update-all',
            'resource_type' => 'fleet',
            'resource_key' => 'update-all',
            'lease_id' => $conflictingLease->id,
            'conflicting_operation_id' => $otherRun->id,
            'conflicting_node' => 'ingress',
            'expires_at' => $conflictingLease->expires_at->toIso8601String(),
        ],
    ])
        ->and($error->payload)->toMatchArray([
            'exit_code' => 1,
            'data' => [
                'code' => 'update_lease_conflict',
                'resource' => 'fleet:update-all',
                'resource_type' => 'fleet',
                'resource_key' => 'update-all',
                'lease_id' => $conflictingLease->id,
                'conflicting_operation_id' => $otherRun->id,
                'conflicting_node' => 'ingress',
                'expires_at' => $conflictingLease->expires_at->toIso8601String(),
            ],
        ]);

    expect($eventMarkers)->toContain('check-updates:done', 'check-fleet-versions:done', 'error')
        ->and(array_search('check-fleet-versions:done', $eventMarkers, true))
        ->toBeLessThan(array_search('error', $eventMarkers, true));
});

it('omits conflicting_node when the conflicting operation has no caller node', function (): void {
    config()->set('app.version', '1.0.0');

    $run = updateRunnerLeaseRun();
    $otherRun = updateRunnerLeaseRun(callerNodeId: null);

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerLeaseSnapshot());
    $conflictingLease = app(UpdateLeaseManager::class)->acquire(
        resourceType: 'fleet',
        resourceKey: 'update-all',
        operationRun: $otherRun,
        ownerToken: 'other-owner',
        ttlSeconds: 300,
    );

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(UpdateLeaseConflict::class);

    expect($run->refresh()->error)->toMatchArray([
        'code' => 'update_lease_conflict',
        'data' => [
            'resource' => 'fleet:update-all',
            'resource_type' => 'fleet',
            'resource_key' => 'update-all',
            'lease_id' => $conflictingLease->id,
            'conflicting_operation_id' => $otherRun->id,
            'expires_at' => $conflictingLease->expires_at->toIso8601String(),
        ],
    ])
        ->and($run->error['data'])->not->toHaveKey('conflicting_node');
});

it('releases fleet gateway and scheduler leases when the gateway phase fails', function (): void {
    $collector = new UpdateRunnerLeaseCollector(failPhase: 'gateway');
    $run = updateRunnerLeaseRun();

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerLeaseSnapshot());
    app()->instance(UpdateRunnerPipeline::class, new RecordingUpdateRunnerPipeline($collector));

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(RuntimeException::class, 'gateway failed');

    expect($collector->records)->toBe([
        ['phase' => 'gateway', 'active' => ['fleet:update-all', 'gateway:orbit-gateway', 'scheduler:orbit-scheduler']],
    ])
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())->toBe(0);
});

it('keeps the fleet lease through workload failure after releasing gateway leases', function (): void {
    $collector = new UpdateRunnerLeaseCollector(failPhase: 'workloads');
    $run = updateRunnerLeaseRun();

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerLeaseSnapshot());
    app()->instance(UpdateRunnerPipeline::class, new RecordingUpdateRunnerPipeline($collector));

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(RuntimeException::class, 'workloads failed');

    expect($collector->records)->toBe([
        ['phase' => 'gateway', 'active' => ['fleet:update-all', 'gateway:orbit-gateway', 'scheduler:orbit-scheduler']],
        ['phase' => 'workloads', 'active' => ['fleet:update-all']],
    ])
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())->toBe(0);
});

it('keeps the fleet lease through verification failure and releases it afterward', function (): void {
    $collector = new UpdateRunnerLeaseCollector(failPhase: 'verification');
    $run = updateRunnerLeaseRun();

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerLeaseSnapshot());
    app()->instance(UpdateRunnerPipeline::class, new RecordingUpdateRunnerPipeline($collector));

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(RuntimeException::class, 'verification failed');

    expect($collector->records)->toBe([
        ['phase' => 'gateway', 'active' => ['fleet:update-all', 'gateway:orbit-gateway', 'scheduler:orbit-scheduler']],
        ['phase' => 'workloads', 'active' => ['fleet:update-all']],
        ['phase' => 'verification', 'active' => ['fleet:update-all']],
    ])
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())->toBe(0);
});

function updateRunnerLeaseRun(?int $callerNodeId = null): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
        callerNodeId: $callerNodeId,
    );
}

/**
 * @param  array<string, mixed>  $manifestOverrides
 */
function updateRunnerLeaseSnapshot(
    string $targetVersion = '1.2.3',
    string $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    array $manifestOverrides = [],
): OperationUpdatePlanSnapshot {
    $manifest = array_replace_recursive([
        'version' => $targetVersion,
        'source' => 'github-release',
        'images' => [
            'gateway' => $gatewayImage,
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => "https://github.com/hardimpactdev/orbit/releases/download/v{$targetVersion}/orbit-linux-amd64",
                'sha256' => str_repeat('b', 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        ],
    ], $manifestOverrides);

    return new OperationUpdatePlanSnapshot(
        targetVersion: $targetVersion,
        gatewayImage: $gatewayImage,
        manifestSource: 'github-release',
        manifestVersion: $targetVersion,
        manifestSnapshot: $manifest,
        cliArtifacts: $manifest['cli_artifacts'],
        roleImages: $manifest['role_images'],
    );
}

final class UpdateRunnerLeaseCollector
{
    /**
     * @var list<array{phase: string, active: list<string>}>
     */
    public array $records = [];

    public function __construct(
        public ?string $failPhase = null,
    ) {}

    public function record(string $phase): void
    {
        $active = UpdateLease::query()
            ->whereNotNull('active_resource_key')
            ->orderBy('id')
            ->get()
            ->map(fn (UpdateLease $lease): string => "{$lease->resource_type}:{$lease->resource_key}")
            ->all();

        $this->records[] = [
            'phase' => $phase,
            'active' => $active,
        ];

        if ($this->failPhase === $phase) {
            throw new RuntimeException("{$phase} failed");
        }
    }
}

final class RecordingUpdateRunnerPipeline extends UpdateRunnerPipeline
{
    public function __construct(
        private UpdateRunnerLeaseCollector $collector,
    ) {}

    #[Override]
    public function updateGateway(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->collector->record('gateway');
    }

    #[Override]
    public function updateWorkloads(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->collector->record('workloads');
    }

    #[Override]
    public function verifyFleet(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->collector->record('verification');
    }
}
