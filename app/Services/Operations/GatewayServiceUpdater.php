<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

class GatewayServiceUpdater
{
    private const string GatewayService = 'orbit_orbit-gateway';

    private const string SchedulerService = 'orbit_orbit-scheduler';

    private const int GatewayHealthCheckAttempts = 90;

    private const int GatewayHealthCheckSleepSeconds = 2;

    public function __construct(
        private readonly ?GatewaySwarmManager $swarm = null,
        private readonly ?OperationRunRecorder $operationRuns = null,
    ) {}

    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $targetImage = GatewayImageReference::fromString($plan->gateway_image);
        $previousSchedulerImage = $this->swarm()->serviceImage(self::SchedulerService);
        $schedulerWasStopped = false;

        try {
            $this->runStep(
                $operationRun,
                'scheduler.stop',
                'Stopping orbit-scheduler service',
                'orbit-scheduler service stopped',
                fn (): null => $this->scaleSchedulerToZero(),
            );
            $schedulerWasStopped = true;

            $this->runStep(
                $operationRun,
                'migrations',
                'Running gateway migrations',
                'Gateway migrations completed',
                fn (): null => $this->runMigrations(),
            );
            $this->runStep(
                $operationRun,
                'gateway.service',
                'Updating orbit-gateway service',
                'orbit-gateway service healthy',
                fn (): null => $this->updateGatewayService($targetImage),
            );
            $this->runStep(
                $operationRun,
                'scheduler.start',
                'Starting orbit-scheduler service',
                'orbit-scheduler service running',
                fn (): null => $this->updateAndStartScheduler($targetImage),
            );
        } catch (Throwable $throwable) {
            if ($schedulerWasStopped) {
                $this->recoverScheduler($operationRun, $previousSchedulerImage, $throwable);
            }

            throw $throwable;
        }
    }

    private function scaleSchedulerToZero(): null
    {
        $this->swarm()->scaleService(self::SchedulerService, 0);

        return null;
    }

    private function runMigrations(): null
    {
        $exitCode = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);

        if ($exitCode !== 0) {
            throw new RuntimeException("Gateway migrations failed with exit code {$exitCode}.");
        }

        return null;
    }

    private function updateGatewayService(GatewayImageReference $targetImage): null
    {
        $this->swarm()->updateServiceImage(self::GatewayService, $targetImage, 'start-first');
        $this->waitForGatewayHealth($targetImage);

        return null;
    }

    private function updateAndStartScheduler(GatewayImageReference $targetImage): null
    {
        $this->swarm()->updateServiceImage(self::SchedulerService, $targetImage, 'stop-first');
        $this->swarm()->scaleService(self::SchedulerService, 1);

        return null;
    }

    private function waitForGatewayHealth(GatewayImageReference $targetImage): void
    {
        for ($attempt = 1; $attempt <= self::GatewayHealthCheckAttempts; $attempt++) {
            $state = $this->swarm()->serviceUpdateState(self::GatewayService);

            if ($state === 'completed') {
                return;
            }

            if ($state === null && $this->gatewayServiceIsConverged($targetImage)) {
                return;
            }

            if ($state !== null && $state !== 'updating') {
                throw new RuntimeException('Gateway service health check failed.');
            }

            if ($attempt < self::GatewayHealthCheckAttempts) {
                Sleep::for(self::GatewayHealthCheckSleepSeconds)->seconds();
            }
        }

        throw new RuntimeException('Gateway service health check failed.');
    }

    private function gatewayServiceIsConverged(GatewayImageReference $targetImage): bool
    {
        if ($this->swarm()->serviceImage(self::GatewayService) !== $targetImage->canonical()) {
            return false;
        }

        return $this->swarm()->serviceReplicas(self::GatewayService) === '1/1';
    }

    private function recoverScheduler(OperationRun $operationRun, ?string $previousSchedulerImage, Throwable $original): void
    {
        $this->operationRuns()->appendStep($operationRun->id, 'scheduler.recovery', 'running', 'Restoring orbit-scheduler service');

        try {
            if ($previousSchedulerImage === null) {
                throw new RuntimeException('Previous scheduler image could not be inspected.');
            }

            $this->swarm()->updateServiceImage(self::SchedulerService, GatewayImageReference::fromString($previousSchedulerImage), 'stop-first');
            $this->swarm()->scaleService(self::SchedulerService, 1);
            $this->operationRuns()->appendStep($operationRun->id, 'scheduler.recovery', 'done', 'orbit-scheduler service restored');
        } catch (Throwable $recovery) {
            $this->operationRuns()->appendStep($operationRun->id, 'scheduler.recovery', 'fail', $recovery->getMessage());
            $this->recordSchedulerRecoveryFailed($operationRun, $previousSchedulerImage, $original, $recovery);
        }
    }

    private function runStep(OperationRun $operationRun, string $key, string $runningMessage, string $doneMessage, callable $callback): null
    {
        $this->operationRuns()->appendStep($operationRun->id, $key, 'running', $runningMessage);

        try {
            $callback();
        } catch (Throwable $throwable) {
            $this->operationRuns()->appendStep($operationRun->id, $key, 'fail', $this->failureMessage($throwable));

            throw $throwable;
        }

        $this->operationRuns()->appendStep($operationRun->id, $key, 'done', $doneMessage);

        return null;
    }

    private function failureMessage(Throwable $throwable): string
    {
        $message = trim($throwable->getMessage());

        return $message !== '' ? $message : 'Gateway service update failed.';
    }

    private function recordSchedulerRecoveryFailed(
        OperationRun $operationRun,
        ?string $previousSchedulerImage,
        Throwable $original,
        Throwable $recovery,
    ): void {
        $this->operationRuns()->appendError($operationRun->id, 'Scheduler recovery failed.', 1, [
            'code' => 'update.scheduler_recovery_failed',
            'recovery_command' => $this->schedulerRecoveryCommand($previousSchedulerImage),
            'original_failure' => $original->getMessage(),
            'recovery_failure' => $recovery->getMessage(),
        ]);
    }

    private function schedulerRecoveryCommand(?string $previousSchedulerImage): string
    {
        $scaleCommand = 'docker service scale --detach=true '.escapeshellarg(self::SchedulerService.'=1');

        if ($previousSchedulerImage === null) {
            return $scaleCommand;
        }

        return 'docker service update --detach=true --image '.escapeshellarg($previousSchedulerImage)
            .' --update-order '.escapeshellarg('stop-first')
            .' --update-failure-action rollback --update-monitor 60s '
            .escapeshellarg(self::SchedulerService)
            ." && {$scaleCommand}";
    }

    private function swarm(): GatewaySwarmManager
    {
        return $this->swarm ?? app(GatewaySwarmManager::class);
    }

    private function operationRuns(): OperationRunRecorder
    {
        return $this->operationRuns ?? app(OperationRunRecorder::class);
    }
}
