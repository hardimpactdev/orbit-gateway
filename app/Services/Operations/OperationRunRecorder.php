<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationEvent;
use App\Models\OperationRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;
use RuntimeException;

/**
 * Records `operation_runs` rows for queued, streamed, or gateway-to-node
 * execution state. Per decision D5, each attempt gets a fresh per-attempt
 * `id` while `operation_id` groups re-mints of the same logical operation.
 *
 * Redaction of `result`, `error`, `stdout_summary`, and `stderr_summary` is
 * the caller's responsibility (typically `OperationResultHandler`); the
 * recorder writes the values as given and does not scrub them.
 */
final readonly class OperationRunRecorder
{
    private const array VALID_LANES = ['host', 'runtime', 'local', 'gateway'];

    public function __construct(
        private OperationEventRecorder $events,
        private OperationEventStreamer $eventStreamer,
    ) {}

    /**
     * @param  array<string, mixed>|null  $result
     * @param  array<string, mixed>|null  $error
     */
    public function queued(
        string $operationId,
        string $lane,
        ?string $internalCommand = null,
        ?string $operationType = null,
        ?int $callerNodeId = null,
        ?int $targetNodeId = null,
        ?string $correlationId = null,
        ?string $queue = null,
        ?array $result = null,
        ?array $error = null,
        ?string $stdoutSummary = null,
        ?string $stderrSummary = null,
    ): OperationRun {
        $this->assertLane($lane);

        return OperationRun::query()->create([
            'id' => (string) Str::uuid(),
            'operation_id' => $operationId,
            'internal_command' => $internalCommand,
            'operation_type' => $operationType,
            'lane' => $lane,
            'status' => OperationStatus::Queued,
            'caller_node_id' => $callerNodeId,
            'target_node_id' => $targetNodeId,
            'correlation_id' => $correlationId,
            'queue' => $queue,
            'result' => $result,
            'error' => $error,
            'stdout_summary' => $stdoutSummary,
            'stderr_summary' => $stderrSummary,
        ]);
    }

    public function running(string $id, ?Carbon $startedAt = null): OperationRun
    {
        $run = $this->findOrFail($id);

        $run->forceFill([
            'status' => OperationStatus::Running,
            'started_at' => $run->started_at ?? ($startedAt ?? Carbon::now()),
        ])->save();

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function succeeded(
        string $id,
        int $exitCode = 0,
        ?array $result = null,
        ?string $stdoutSummary = null,
        ?string $stderrSummary = null,
    ): OperationRun {
        return $this->finalize(
            id: $id,
            status: OperationStatus::Succeeded,
            exitCode: $exitCode,
            result: $result,
            error: null,
            stdoutSummary: $stdoutSummary,
            stderrSummary: $stderrSummary,
        );
    }

    /**
     * @param  array<string, mixed>|null  $error
     */
    public function failed(
        string $id,
        ?int $exitCode = null,
        ?array $error = null,
        ?string $stdoutSummary = null,
        ?string $stderrSummary = null,
    ): OperationRun {
        return $this->finalize(
            id: $id,
            status: OperationStatus::Failed,
            exitCode: $exitCode,
            result: null,
            error: $error,
            stdoutSummary: $stdoutSummary,
            stderrSummary: $stderrSummary,
        );
    }

    /**
     * @param  array<string, mixed>|null  $error
     */
    public function rejected(string $id, ?array $error = null): OperationRun
    {
        return $this->finalize(
            id: $id,
            status: OperationStatus::Rejected,
            exitCode: null,
            result: null,
            error: $error,
            stdoutSummary: null,
            stderrSummary: null,
        );
    }

    public function expired(string $id): OperationRun
    {
        return $this->finalize(
            id: $id,
            status: OperationStatus::Expired,
            exitCode: null,
            result: null,
            error: null,
            stdoutSummary: null,
            stderrSummary: null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function appendEvent(string $operationRunId, string $eventType, array $payload, array $metadata = []): OperationEvent
    {
        return $this->events->append($operationRunId, $eventType, $payload, $metadata);
    }

    /**
     * @param  list<array{key: string, label: string, doneLabel?: string}>  $steps
     * @param  array<string, mixed>  $metadata
     */
    public function appendTree(string $operationRunId, string $title, array $steps, array $metadata = []): OperationEvent
    {
        return $this->events->tree($operationRunId, $title, $steps, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function appendStep(string $operationRunId, string $key, string $status, ?string $message = null, array $metadata = []): OperationEvent
    {
        return $this->events->step($operationRunId, $key, $status, $message, $metadata);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function appendComplete(string $operationRunId, int $exitCode, array $data = [], array $metadata = []): OperationEvent
    {
        return $this->events->complete($operationRunId, $exitCode, $data, $metadata);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function appendError(string $operationRunId, string $message, int $exitCode = 1, array $data = [], array $metadata = []): OperationEvent
    {
        return $this->events->error($operationRunId, $message, $exitCode, $data, $metadata);
    }

    /**
     * @return Collection<int, OperationEvent>
     */
    public function eventsAfter(string $operationRunId, ?int $lastSequence = null): Collection
    {
        return $this->eventStreamer->eventsAfter($operationRunId, $lastSequence);
    }

    /**
     * @param  array<string, mixed>|null  $result
     * @param  array<string, mixed>|null  $error
     */
    private function finalize(
        string $id,
        OperationStatus $status,
        ?int $exitCode,
        ?array $result,
        ?array $error,
        ?string $stdoutSummary,
        ?string $stderrSummary,
    ): OperationRun {
        $run = $this->findOrFail($id);

        $run->forceFill(array_filter([
            'status' => $status,
            'finished_at' => Carbon::now(),
            'exit_code' => $exitCode,
            'result' => $result,
            'error' => $error,
            'stdout_summary' => $stdoutSummary,
            'stderr_summary' => $stderrSummary,
        ], fn (mixed $value): bool => $value !== null))->save();

        return $run->refresh();
    }

    private function findOrFail(string $id): OperationRun
    {
        $run = OperationRun::query()->find($id);

        if ($run === null) {
            throw new RuntimeException("OperationRun {$id} not found.");
        }

        return $run;
    }

    private function assertLane(string $lane): void
    {
        if (! in_array($lane, self::VALID_LANES, true)) {
            throw new RuntimeException(
                "OperationRun lane must be one of host, runtime, local, gateway; got '{$lane}'.",
            );
        }
    }
}
