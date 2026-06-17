<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationEvent;
use App\Models\OperationRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class OperationEventRecorder
{
    public function __construct(
        private ResultBoundaryRedactionPolicy $redaction,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function append(OperationRun|string $operationRun, string $eventType, array $payload, array $metadata = []): OperationEvent
    {
        $eventType = trim($eventType);

        if ($eventType === '') {
            throw new RuntimeException('Operation event type cannot be empty.');
        }

        $this->redaction->assertSafe([
            'payload' => $payload,
            'metadata' => $metadata,
        ], 'progress');

        return DB::transaction(function () use ($operationRun, $eventType, $payload, $metadata): OperationEvent {
            $operationRun = $this->findOrFail($operationRun);

            $sequence = (int) OperationEvent::query()
                ->where('operation_run_id', $operationRun->id)
                ->lockForUpdate()
                ->max('sequence') + 1;

            return OperationEvent::query()->create([
                'operation_run_id' => $operationRun->id,
                'sequence' => $sequence,
                'event_type' => $eventType,
                'payload' => $payload,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);
        });
    }

    /**
     * @param  list<array{key: string, label: string, doneLabel?: string}>  $steps
     * @param  array<string, mixed>  $metadata
     */
    public function tree(OperationRun|string $operationRun, string $title, array $steps, array $metadata = []): OperationEvent
    {
        return $this->append($operationRun, 'tree', [
            'title' => $title,
            'steps' => $steps,
        ], $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function step(OperationRun|string $operationRun, string $key, string $status, ?string $message = null, array $metadata = []): OperationEvent
    {
        $payload = [
            'key' => $key,
            'status' => $status,
        ];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return $this->append($operationRun, 'step', $payload, $metadata);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function complete(OperationRun|string $operationRun, int $exitCode, array $data = [], array $metadata = []): OperationEvent
    {
        return $this->append($operationRun, 'complete', [
            'exit_code' => $exitCode,
            'data' => $data,
        ], $metadata);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function error(OperationRun|string $operationRun, string $message, int $exitCode = 1, array $data = [], array $metadata = []): OperationEvent
    {
        return $this->append($operationRun, 'error', [
            'exit_code' => $exitCode,
            'message' => $message,
            'data' => $data,
        ], $metadata);
    }

    private function findOrFail(OperationRun|string $operationRun): OperationRun
    {
        if ($operationRun instanceof OperationRun) {
            return $operationRun;
        }

        $run = OperationRun::query()->find($operationRun);

        if ($run === null) {
            throw new RuntimeException("OperationRun {$operationRun} not found.");
        }

        return $run;
    }
}
