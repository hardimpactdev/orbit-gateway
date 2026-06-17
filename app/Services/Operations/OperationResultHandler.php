<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;

/**
 * Maps an operation_type plus one typed remote result into gateway-owned
 * writes against `operation_runs`. The handler is the single persistence
 * boundary for typed results: it rejects unrecognized result keys and any
 * payload that contains forbidden secret material before delegating to
 * {@see OperationRunRecorder}.
 *
 * Streaming progress frames belong to a separate framed-progress transport
 * in ORBIT-CLI-10E and must run the same {@see ResultBoundaryRedactionPolicy}
 * before persistence or broadcast.
 */
final readonly class OperationResultHandler
{
    public function __construct(
        private OperationResultRegistry $registry,
        private ResultBoundaryRedactionPolicy $redaction,
        private OperationRunRecorder $recorder,
    ) {}

    /**
     * Validate and persist a typed successful result against an existing
     * operation_runs row.
     *
     * @param  array<string, mixed>  $result
     */
    public function recordSuccess(
        string $operationRunId,
        string $operationType,
        array $result,
        int $exitCode = 0,
        ?string $stdoutSummary = null,
        ?string $stderrSummary = null,
    ): OperationRun {
        $this->assertRecognized($operationType, $result);
        $this->redaction->assertSafe($result, 'result');

        return $this->recorder->succeeded(
            id: $operationRunId,
            exitCode: $exitCode,
            result: $result,
            stdoutSummary: $stdoutSummary,
            stderrSummary: $stderrSummary,
        );
    }

    /**
     * Validate and persist a typed failure payload against an existing
     * operation_runs row. Failure payloads must also be free of forbidden
     * key fragments and PEM material before they are written.
     *
     * @param  array<string, mixed>  $error
     */
    public function recordFailure(
        string $operationRunId,
        array $error,
        ?int $exitCode = null,
        ?string $stdoutSummary = null,
        ?string $stderrSummary = null,
    ): OperationRun {
        $this->redaction->assertSafe($error, 'error');

        return $this->recorder->failed(
            id: $operationRunId,
            exitCode: $exitCode,
            error: $error,
            stdoutSummary: $stdoutSummary,
            stderrSummary: $stderrSummary,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertRecognized(string $operationType, array $result): void
    {
        if (! $this->registry->has($operationType)) {
            throw new OperationPayloadRejected(
                "operation.result_unrecognized: no contract registered for operation_type '{$operationType}'.",
                errorCode: 'operation.result_unrecognized',
                meta: [
                    'operation_type' => $operationType,
                ],
            );
        }

        $allowed = $this->registry->get($operationType)->allowedKeys();

        foreach (array_keys($result) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new OperationPayloadRejected(
                    "operation.result_unrecognized: key '".(string) $key."' is not allowed for operation_type '{$operationType}'.",
                    errorCode: 'operation.result_unrecognized',
                    meta: [
                        'operation_type' => $operationType,
                        'unrecognized_key' => (string) $key,
                        'allowed_keys' => $allowed,
                    ],
                );
            }
        }
    }
}
