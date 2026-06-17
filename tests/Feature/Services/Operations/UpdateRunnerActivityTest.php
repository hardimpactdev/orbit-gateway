<?php

declare(strict_types=1);

use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Operations\FleetUpdateVerificationFailed;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdateRunner;
use App\Services\Operations\UpdateRunnerPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

it('records a completed activity entry when the fleet update succeeds', function (): void {
    $run = updateRunnerActivityRun();

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerActivitySnapshot(
        targetVersion: '3.0.0',
        gatewayImage: 'ghcr.io/hardimpactdev/orbit-gateway:3.0.0@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
    ));

    app()->instance(UpdateRunnerPipeline::class, new NoopUpdateRunnerPipeline);

    app(UpdateRunner::class)->run($run->id);

    $entry = Activity::query()->where('event', 'update:all')->first();

    expect($entry)->not->toBeNull();
    expect($entry->subject_type)->toBe(OperationRun::class);
    expect($entry->subject_id)->toBe($run->id);
    expect($entry->properties->get('type'))->toBe('write');
    expect($entry->properties->get('scope'))->toBe('fleet');
    expect($entry->properties->get('operation_run_id'))->toBe($run->id);
    expect($entry->properties->get('status'))->toBe('completed');
    expect($entry->properties->get('target_version'))->toBe('3.0.0');
    expect($entry->properties->get('manifest_version'))->toBe('3.0.0');
    expect($entry->properties->get('manifest_source'))->toBe('github-release');
    expect($entry->properties->get('gateway_image_digest'))->toBe('sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc');
    expect($entry->properties->has('failed_step'))->toBeFalse();

    // Must not contain secrets or raw output
    $all = $entry->properties->toArray();
    expect(array_key_exists('stdout', $all))->toBeFalse();
    expect(array_key_exists('stderr', $all))->toBeFalse();
    expect(array_key_exists('ssh_output', $all))->toBeFalse();
    expect(array_key_exists('operation_token', $all))->toBeFalse();
    expect(array_key_exists('env', $all))->toBeFalse();
    expect(array_key_exists('private_key', $all))->toBeFalse();
});

it('records a failed activity entry with failed_step when the fleet update fails', function (): void {
    $run = updateRunnerActivityRun();

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerActivitySnapshot(
        targetVersion: '4.0.0',
        gatewayImage: 'ghcr.io/hardimpactdev/orbit-gateway:4.0.0@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
    ));

    $failingPipeline = new class extends NoopUpdateRunnerPipeline
    {
        #[Override]
        public function verifyFleet(OperationRun $operationRun, OperationUpdatePlan $plan): void
        {
            throw new FleetUpdateVerificationFailed(
                failureCode: 'verification.gateway_unhealthy',
                publicMessage: 'Gateway health check failed.',
            );
        }
    };

    app()->instance(UpdateRunnerPipeline::class, $failingPipeline);

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(FleetUpdateVerificationFailed::class);

    $entry = Activity::query()->where('event', 'update:all')->first();

    expect($entry)->not->toBeNull();
    expect($entry->subject_type)->toBe(OperationRun::class);
    expect($entry->subject_id)->toBe($run->id);
    expect($entry->properties->get('type'))->toBe('write');
    expect($entry->properties->get('scope'))->toBe('fleet');
    expect($entry->properties->get('operation_run_id'))->toBe($run->id);
    expect($entry->properties->get('status'))->toBe('failed');
    expect($entry->properties->get('target_version'))->toBe('4.0.0');
    expect($entry->properties->get('manifest_version'))->toBe('4.0.0');
    expect($entry->properties->get('manifest_source'))->toBe('github-release');
    expect($entry->properties->get('gateway_image_digest'))->toBe('sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd');
    expect($entry->properties->get('failed_step'))->toBe('verification');
});

it('does not change the runner outcome or operation status when the activity_log table is unavailable', function (): void {
    $run = updateRunnerActivityRun();

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerActivitySnapshot());
    app()->instance(UpdateRunnerPipeline::class, new NoopUpdateRunnerPipeline);

    // Break the activitylog database connection so that any log() call throws a database exception.
    // The runner must swallow the exception and still mark the operation as succeeded.
    config()->set('activitylog.database_connection', 'nonexistent_connection_for_testing');

    // Must not throw — logging failure is swallowed
    app(UpdateRunner::class)->run($run->id);

    expect($run->refresh()->status)->toBe(OperationStatus::Succeeded);
});

function updateRunnerActivityRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

function updateRunnerActivitySnapshot(
    string $targetVersion = '1.0.0',
    string $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.0.0@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
): OperationUpdatePlanSnapshot {
    $manifest = [
        'version' => $targetVersion,
        'source' => 'github-release',
        'images' => ['gateway' => $gatewayImage],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => "https://github.com/hardimpactdev/orbit/releases/download/v{$targetVersion}/orbit-linux-amd64",
                'sha256' => str_repeat('a', 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2-alpine',
        ],
    ];

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

class NoopUpdateRunnerPipeline extends UpdateRunnerPipeline
{
    public function __construct() {}

    #[Override]
    public function updateGateway(OperationRun $operationRun, OperationUpdatePlan $plan): void {}

    #[Override]
    public function updateWorkloads(OperationRun $operationRun, OperationUpdatePlan $plan): void {}

    #[Override]
    public function verifyFleet(OperationRun $operationRun, OperationUpdatePlan $plan): void {}
}
