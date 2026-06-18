<?php

declare(strict_types=1);

use App\Models\OperationEvent;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Operations\GatewayServiceUpdater;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('updates gateway and scheduler services to the plan image after in-process migrations and gateway health', function (): void {
    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();
    $operations = [];

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturnUsing(function () use (&$operations): int {
            $operations[] = 'artisan:migrate';

            return 0;
        });

    Process::fake(function ($process) use (&$operations, $plan, $previousImage) {
        $command = (string) $process->command;
        $operations[] = $command;

        return match ($command) {
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "{$previousImage}\n"),
            "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
            "docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'" => Process::result(),
            "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(output: "completed\n"),
            "docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" => Process::result(),
            "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
            default => throw new RuntimeException("Unexpected process command [{$command}]."),
        };
    });

    app(GatewayServiceUpdater::class)->update($run, $plan);

    expect($operations)->toBe([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'",
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'",
        'artisan:migrate',
        "docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'",
        "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'",
        "docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'",
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'",
    ])
        ->and(array_filter($operations, fn (string $operation): bool => str_starts_with($operation, 'docker run')))->toBe([]);

    expect(OperationEvent::query()
        ->where('operation_run_id', $run->id)
        ->where('event_type', 'error')
        ->exists())->toBeFalse();
});

it('restores the scheduler previous image and replica when gateway migrations fail', function (): void {
    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andThrow(new RuntimeException('migration failed'));

    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "{$previousImage}\n"),
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
        "docker service update --detach=true --image '{$previousImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" => Process::result(),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
    ]);

    expect(fn () => app(GatewayServiceUpdater::class)->update($run, $plan))
        ->toThrow(RuntimeException::class, 'migration failed');

    Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=0'");
    Process::assertRan("docker service update --detach=true --image '{$previousImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'");
    Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, $plan->gateway_image)
        && str_contains((string) $process->command, "'orbit_orbit-gateway'"));
});

it('waits for a detached gateway service update to complete before starting the scheduler', function (): void {
    Sleep::fake();

    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();
    $gatewayStates = ['updating', 'completed'];
    $gatewayStateChecks = 0;

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturn(0);

    Process::fake(function ($process) use (&$gatewayStates, &$gatewayStateChecks, $plan, $previousImage) {
        $command = (string) $process->command;

        if ($command === "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'") {
            return Process::result(output: "{$previousImage}\n");
        }

        if ($command === "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'") {
            $gatewayStateChecks++;

            return Process::result(output: (array_shift($gatewayStates) ?? 'completed')."\n");
        }

        if (str_starts_with($command, 'docker service scale ')) {
            return Process::result();
        }

        if (str_starts_with($command, 'docker service update ')
            && str_contains($command, $plan->gateway_image)) {
            return Process::result();
        }

        throw new RuntimeException("Unexpected process command [{$command}].");
    });

    app(GatewayServiceUpdater::class)->update($run, $plan);

    expect($gatewayStateChecks)->toBe(2);

    Sleep::assertSleptTimes(1);
});

it('treats a same-image gateway service update with no Docker update status as healthy when the service is converged', function (): void {
    Sleep::fake();

    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();
    $operations = [];

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturnUsing(function () use (&$operations): int {
            $operations[] = 'artisan:migrate';

            return 0;
        });

    Process::fake(function ($process) use (&$operations, $plan, $previousImage) {
        $command = (string) $process->command;
        $operations[] = $command;

        return match ($command) {
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "{$previousImage}\n"),
            "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
            "docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'" => Process::result(),
            "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(
                errorOutput: "template: :1:15: executing \"\" at <.UpdateStatus.State>: map has no entry for key \"UpdateStatus\"\n",
                exitCode: 1,
            ),
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(output: "{$plan->gateway_image}\n"),
            "docker service ls --filter 'name=orbit_orbit-gateway' --format '{{.Replicas}}'" => Process::result(output: "1/1\n"),
            "docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" => Process::result(),
            "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
            default => throw new RuntimeException("Unexpected process command [{$command}]."),
        };
    });

    app(GatewayServiceUpdater::class)->update($run, $plan);

    expect($operations)->toBe([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'",
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'",
        'artisan:migrate',
        "docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'",
        "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'",
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'",
        "docker service ls --filter 'name=orbit_orbit-gateway' --format '{{.Replicas}}'",
        "docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'",
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'",
    ]);

    Sleep::assertSleptTimes(0);
});

it('restores the scheduler previous image and replica when the updated gateway fails health', function (): void {
    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturn(0);

    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "{$previousImage}\n"),
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
        "docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'" => Process::result(),
        "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(output: "rollback_completed\n"),
        "docker service update --detach=true --image '{$previousImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" => Process::result(),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
    ]);

    expect(fn () => app(GatewayServiceUpdater::class)->update($run, $plan))
        ->toThrow(RuntimeException::class, 'Gateway service health check failed');

    Process::assertRan("docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'");
    Process::assertRan("docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'");
    Process::assertRan("docker service update --detach=true --image '{$previousImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'");
    Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
});

it('records a recovery failed event when the scheduler cannot be scaled back to one replica', function (): void {
    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andThrow(new RuntimeException('migration failed'));

    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "{$previousImage}\n"),
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
        "docker service update --detach=true --image '{$previousImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" => Process::result(),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(exitCode: 1, errorOutput: "scheduler scale failed\n"),
    ]);

    expect(fn () => app(GatewayServiceUpdater::class)->update($run, $plan))
        ->toThrow(RuntimeException::class);

    $event = OperationEvent::query()
        ->where('operation_run_id', $run->id)
        ->where('event_type', 'error')
        ->latest('sequence')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event?->payload)->toMatchArray([
            'exit_code' => 1,
        ])
        ->and($event?->payload['message'])->toContain('Scheduler recovery failed')
        ->and($event?->payload['data']['code'])->toBe('update.scheduler_recovery_failed')
        ->and($event?->payload['data']['recovery_command'])->toContain($previousImage)
        ->and($event?->payload['data']['recovery_command'])->toContain('docker service update --detach=true')
        ->and($event?->payload['data']['recovery_command'])->toContain("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
});

function gatewayServiceUpdaterRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

function gatewayServiceUpdaterPlan(OperationRun $run): OperationUpdatePlan
{
    $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    return OperationUpdatePlan::query()->create([
        'operation_run_id' => $run->id,
        'target_version' => '1.2.3',
        'gateway_image' => $gatewayImage,
        'manifest_source' => 'github-release',
        'manifest_version' => '1.2.3',
        'manifest_snapshot' => [
            'version' => '1.2.3',
            'images' => [
                'gateway' => $gatewayImage,
            ],
        ],
        'cli_artifacts' => [],
        'role_images' => [],
    ]);
}

function gatewayServiceUpdaterPreviousImage(): string
{
    return 'ghcr.io/hardimpactdev/orbit-gateway:1.2.2@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
}
