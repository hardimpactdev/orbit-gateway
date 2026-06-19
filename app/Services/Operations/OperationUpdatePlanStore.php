<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use Illuminate\Database\QueryException;
use RuntimeException;

class OperationUpdatePlanStore
{
    public function create(OperationRun|string $operationRun, OperationUpdatePlanSnapshot $snapshot): OperationUpdatePlan
    {
        $operationRunId = $this->operationRunId($operationRun);

        if ($this->forOperationRun($operationRunId) instanceof OperationUpdatePlan) {
            throw new RuntimeException("Operation update plan for run [{$operationRunId}] already exists.");
        }

        try {
            /** @var OperationUpdatePlan $plan */
            $plan = OperationUpdatePlan::query()->create([
                'operation_run_id' => $operationRunId,
                'target_version' => $snapshot->targetVersion,
                'gateway_image' => $snapshot->gatewayImage,
                'manifest_source' => $snapshot->manifestSource,
                'manifest_version' => $snapshot->manifestVersion,
                'manifest_snapshot' => $snapshot->manifestSnapshot,
                'cli_artifacts' => $snapshot->cliArtifacts,
                'role_images' => $snapshot->roleImages,
            ]);
        } catch (QueryException $exception) {
            if ($this->causedByUniqueConstraint($exception)) {
                throw new RuntimeException("Operation update plan for run [{$operationRunId}] already exists.", previous: $exception);
            }

            throw $exception;
        }

        return $plan;
    }

    public function forOperationRun(OperationRun|string $operationRun): ?OperationUpdatePlan
    {
        $operationRunId = $this->operationRunId($operationRun);

        /** @var OperationUpdatePlan|null $plan */
        $plan = OperationUpdatePlan::query()
            ->where('operation_run_id', $operationRunId)
            ->first();

        return $plan;
    }

    private function operationRunId(OperationRun|string $operationRun): string
    {
        $operationRunId = $operationRun instanceof OperationRun ? $operationRun->id : trim($operationRun);

        if ($operationRunId === '') {
            throw new RuntimeException('Operation update plan operation_run_id cannot be empty.');
        }

        return $operationRunId;
    }

    private function causedByUniqueConstraint(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['19', '1062'], true)
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');
    }
}
