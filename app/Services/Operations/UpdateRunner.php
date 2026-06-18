<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Exceptions\UpdateLeaseConflict;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\ActivityLogger;
use RuntimeException;
use Throwable;

final readonly class UpdateRunner
{
    private const string FleetResourceKey = 'update-all';

    private const string GatewayResourceKey = 'orbit-gateway';

    private const string SchedulerResourceKey = 'orbit-scheduler';

    public function __construct(
        private OperationRunRecorder $operationRuns,
        private OperationUpdatePlanStore $updatePlans,
        private UpdateLeaseManager $leases,
        private UpdateRunnerPipeline $pipeline,
        private FleetVersionProbe $fleetVersions,
        private ActivityLogger $activityLogger,
    ) {}

    public function start(string $operationRunId): OperationUpdatePlan
    {
        [$operationRun, $plan] = $this->loadRunnableContext($operationRunId);

        $this->markStarted($operationRun, $plan);

        return $plan;
    }

    public function run(string $operationRunId): OperationUpdatePlan
    {
        [$operationRun, $plan] = $this->loadRunnableContext($operationRunId);

        try {
            $this->leases->withLease(
                resourceType: 'fleet',
                resourceKey: self::FleetResourceKey,
                operationRun: $operationRun,
                ownerToken: $this->ownerToken($operationRun, 'fleet', self::FleetResourceKey),
                ttlSeconds: $this->leaseTtlSeconds(),
                callback: function () use ($operationRun, $plan): void {
                    $this->markStarted($operationRun, $plan);
                    $this->operationRuns->appendStep($operationRun->id, 'lease.fleet', 'done', 'Fleet update lease acquired');
                    $this->runCheckSteps($operationRun, $plan);
                    $this->runPhase(
                        $operationRun,
                        'gateway',
                        'Updating gateway services',
                        'Gateway services updated',
                        function () use ($operationRun, $plan): null {
                            $this->updateGateway($operationRun, $plan);

                            return null;
                        },
                    );
                    $this->runPhase(
                        $operationRun,
                        'workload-nodes',
                        'Updating workload nodes',
                        'Workload nodes updated',
                        function () use ($operationRun, $plan): null {
                            $this->pipeline->updateWorkloads($operationRun, $plan);

                            return null;
                        },
                    );
                    $this->runPhase(
                        $operationRun,
                        'verification',
                        'Verifying fleet update',
                        'Fleet update verified',
                        function () use ($operationRun, $plan): null {
                            $this->pipeline->verifyFleet($operationRun, $plan);

                            return null;
                        },
                    );
                },
            );
        } catch (Throwable $exception) {
            $this->markFailed($operationRun, $exception);

            throw $exception;
        }

        $this->markSucceeded($operationRun, $plan);

        return $plan;
    }

    /**
     * Emit the two contract check steps before any side effect: a version check
     * that resolves the target release, and a fleet version probe that counts
     * how many installations are behind it. The probe is read-only; it never
     * mutates fleet state.
     */
    private function runCheckSteps(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->operationRuns->appendStep($operationRun->id, 'check-updates', 'running', 'Checking');
        $this->operationRuns->appendStep($operationRun->id, 'check-updates', 'done', "latest version is {$plan->target_version}");

        $this->operationRuns->appendStep($operationRun->id, 'check-fleet-versions', 'running', 'Checking');

        $report = $this->fleetVersions->probe($operationRun, $plan);

        $this->operationRuns->appendStep(
            $operationRun->id,
            'check-fleet-versions',
            'done',
            $this->fleetVersionsMessage($report->outdatedCount, $plan->target_version),
        );
    }

    private function fleetVersionsMessage(int $outdatedCount, string $targetVersion): string
    {
        if ($outdatedCount === 0) {
            return "all nodes running on {$targetVersion}";
        }

        $noun = $outdatedCount === 1 ? 'node' : 'nodes';

        return "{$outdatedCount} outdated {$noun} found";
    }

    private function updateGateway(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->leases->withLease(
            resourceType: 'gateway',
            resourceKey: self::GatewayResourceKey,
            operationRun: $operationRun,
            ownerToken: $this->ownerToken($operationRun, 'gateway', self::GatewayResourceKey),
            ttlSeconds: $this->leaseTtlSeconds(),
            callback: function () use ($operationRun, $plan): void {
                $this->leases->withLease(
                    resourceType: 'scheduler',
                    resourceKey: self::SchedulerResourceKey,
                    operationRun: $operationRun,
                    ownerToken: $this->ownerToken($operationRun, 'scheduler', self::SchedulerResourceKey),
                    ttlSeconds: $this->leaseTtlSeconds(),
                    callback: function () use ($operationRun, $plan): null {
                        $this->operationRuns->appendStep($operationRun->id, 'lease.gateway', 'done', 'Gateway and scheduler update leases acquired');

                        return $this->updateGatewayWithSchedulerLease($operationRun, $plan);
                    },
                );
            },
        );
    }

    private function updateGatewayWithSchedulerLease(OperationRun $operationRun, OperationUpdatePlan $plan): null
    {
        $this->pipeline->updateGateway($operationRun, $plan);

        return null;
    }

    private function runPhase(OperationRun $operationRun, string $key, string $runningMessage, string $doneMessage, callable $callback): null
    {
        $this->operationRuns->appendStep($operationRun->id, $key, 'running', $runningMessage);

        try {
            $callback();
        } catch (Throwable $exception) {
            $this->operationRuns->appendStep($operationRun->id, $key, 'fail', $this->phaseFailureMessage($exception));

            throw $exception;
        }

        $this->operationRuns->appendStep($operationRun->id, $key, 'done', $doneMessage);

        return null;
    }

    private function markStarted(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->operationRuns->running($operationRun->id);
        $this->operationRuns->appendStep($operationRun->id, 'runner', 'running', 'Update runner started', [
            'target_version' => $plan->target_version,
            'gateway_image' => $plan->gateway_image,
            'manifest_source' => $plan->manifest_source,
            'manifest_version' => $plan->manifest_version,
        ]);
    }

    private function markSucceeded(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $result = [
            'status' => 'succeeded',
            'target_version' => $plan->target_version,
            'manifest_version' => $plan->manifest_version,
        ];

        $this->operationRuns->appendComplete($operationRun->id, 0, $result);
        $this->operationRuns->succeeded($operationRun->id, result: $result);

        $this->logOutcomeActivity($operationRun, $plan, 'completed');
    }

    private function markFailed(OperationRun $operationRun, Throwable $exception): void
    {
        $failure = $this->failurePayload($exception);

        $this->operationRuns->appendError($operationRun->id, $failure['message'], 1, [
            'code' => $failure['code'],
            ...$failure['data'],
        ]);
        $this->operationRuns->failed($operationRun->id, error: [
            'code' => $failure['code'],
            'message' => $failure['message'],
            ...($failure['data'] === [] ? [] : ['data' => $failure['data']]),
        ]);

        $plan = $this->updatePlans->forOperationRun($operationRun->id);

        if ($plan instanceof OperationUpdatePlan) {
            $this->logOutcomeActivity($operationRun, $plan, 'failed', $this->failedStep($exception));
        }
    }

    private function logOutcomeActivity(OperationRun $operationRun, OperationUpdatePlan $plan, string $status, ?string $failedStep = null): void
    {
        try {
            $this->activityLogger->log(
                new FleetUpdateOutcomeActivity($operationRun, $plan, $status, $failedStep),
                channel: 'fleet_update',
                causer: null,
            );
        } catch (Throwable) {
            // Activity logging is best-effort; failure must not change the runner result.
        }
    }

    private function failedStep(Throwable $exception): string
    {
        if ($exception instanceof FleetUpdateVerificationFailed) {
            return 'verification';
        }

        if ($exception instanceof WorkloadNodeUpdateFailed) {
            return 'workloads';
        }

        if ($exception instanceof UpdateLeaseConflict) {
            return match ($exception->resourceType) {
                'gateway', 'scheduler' => 'gateway',
                'node' => 'workloads',
                default => 'fleet_lease',
            };
        }

        return 'runner';
    }

    /**
     * @return array{code: string, message: string, data: array<string, mixed>}
     */
    private function failurePayload(Throwable $exception): array
    {
        if ($exception instanceof FleetUpdateVerificationFailed) {
            return [
                'code' => $exception->failureCode,
                'message' => $exception->publicMessage,
                'data' => [],
            ];
        }

        if ($exception instanceof WorkloadNodeUpdateFailed) {
            return [
                'code' => 'workload_update_failed',
                'message' => $exception->getMessage(),
                'data' => [
                    'failed_targets' => $exception->failedTargets,
                    'target_results' => $exception->targetResults,
                ],
            ];
        }

        if ($exception instanceof UpdateLeaseConflict) {
            return [
                'code' => $exception->resourceType === 'node' ? 'update.node_locked' : 'update_lease_conflict',
                'message' => $exception->getMessage(),
                'data' => [
                    'resource' => "{$exception->resourceType}:{$exception->resourceKey}",
                    'resource_type' => $exception->resourceType,
                    'resource_key' => $exception->resourceKey,
                    'lease_id' => $exception->leaseId,
                    'conflicting_operation_id' => $exception->operationRunId,
                    'expires_at' => $exception->expiresAt->toIso8601String(),
                ],
            ];
        }

        return [
            'code' => 'update_runner_failed',
            'message' => 'Update runner failed.',
            'data' => [],
        ];
    }

    private function phaseFailureMessage(Throwable $exception): string
    {
        if ($exception instanceof FleetUpdateVerificationFailed) {
            return $exception->publicMessage;
        }

        $message = trim($exception->getMessage());

        return $message !== '' ? $message : 'Update phase failed.';
    }

    /**
     * @return array{0: OperationRun, 1: OperationUpdatePlan}
     */
    private function loadRunnableContext(string $operationRunId): array
    {
        $operationRunId = trim($operationRunId);

        if ($operationRunId === '') {
            throw new RuntimeException('Update runner operation_run_id cannot be empty.');
        }

        $operationRun = OperationRun::query()->find($operationRunId);

        if (! $operationRun instanceof OperationRun) {
            throw new RuntimeException("Operation run [{$operationRunId}] was not found.");
        }

        if ($operationRun->status->isTerminal()) {
            throw new RuntimeException("Operation run [{$operationRunId}] is already terminal.");
        }

        $plan = $this->updatePlans->forOperationRun($operationRunId);

        if (! $plan instanceof OperationUpdatePlan) {
            throw new RuntimeException("Operation update plan for run [{$operationRunId}] was not found.");
        }

        return [$operationRun, $plan];
    }

    private function ownerToken(OperationRun $operationRun, string $resourceType, string $resourceKey): string
    {
        return hash('sha256', implode(':', [
            'update-runner',
            $operationRun->id,
            $resourceType,
            $resourceKey,
        ]));
    }

    private function leaseTtlSeconds(): int
    {
        $ttlSeconds = (int) config('orbit.updates.lease_ttl_seconds', 300);

        return max(1, $ttlSeconds);
    }
}
