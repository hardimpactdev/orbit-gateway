<?php

declare(strict_types=1);

use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Operations\FleetUpdateVerifier;
use App\Services\Operations\GatewayServiceUpdater;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

it('loads the immutable update plan and writes runner start events', function (): void {
    $run = updateRunnerCommandRun();
    app()->instance(GatewayServiceUpdater::class, new UpdateRunnerCommandNoopGatewayUpdater);
    app()->instance(FleetUpdateVerifier::class, new UpdateRunnerCommandNoopFleetVerifier);

    app(OperationUpdatePlanStore::class)->create(
        $run,
        updateRunnerCommandSnapshot(
            targetVersion: '1.2.3',
            gatewayImage: 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ),
    );

    $this->artisan('orbit:update-runner', ['--operation-run-id' => $run->id])
        ->expectsOutputToContain("Update runner started for operation run {$run->id}.")
        ->assertSuccessful();

    $run->refresh();
    $event = $run->events()->firstOrFail();

    expect($run->status)->toBe(OperationStatus::Succeeded)
        ->and($run->events()->pluck('event_type')->last())->toBe('complete')
        ->and($event->event_type)->toBe('step')
        ->and($event->payload)->toMatchArray([
            'key' => 'runner',
            'status' => 'running',
            'message' => 'Update runner started',
        ])
        ->and($event->metadata)->toMatchArray([
            'target_version' => '1.2.3',
            'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'manifest_version' => '1.2.3',
        ]);
});

it('fails fast when the operation run has no persisted update plan or deferred start payload', function (): void {
    $run = updateRunnerCommandRun();

    $this->artisan('orbit:update-runner', ['--operation-run-id' => $run->id])
        ->expectsOutputToContain('Deferred update start request payload was not found on the operation run.')
        ->assertFailed();

    expect($run->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($run->events()->where('event_type', 'step')->count())->toBeGreaterThan(0);
});

it('fails fast when the operation run is already terminal', function (): void {
    $run = updateRunnerCommandRun();

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerCommandSnapshot());
    app(OperationRunRecorder::class)->succeeded($run->id, result: ['done' => true]);

    $this->artisan('orbit:update-runner', ['--operation-run-id' => $run->id])
        ->expectsOutputToContain("Operation run [{$run->id}] is already terminal.")
        ->assertFailed();

    expect($run->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($run->events()->count())->toBe(0);
});

it('requires an operation run id', function (): void {
    $this->artisan('orbit:update-runner')
        ->expectsOutputToContain('The --operation-run-id option is required.')
        ->assertFailed();
});

function updateRunnerCommandRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

class UpdateRunnerCommandNoopGatewayUpdater extends GatewayServiceUpdater
{
    #[Override]
    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }
}

final class UpdateRunnerCommandNoopFleetVerifier extends FleetUpdateVerifier
{
    public function __construct() {}

    #[Override]
    public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }
}

/**
 * @param  array<string, mixed>  $manifestOverrides
 */
function updateRunnerCommandSnapshot(
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
