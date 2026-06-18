<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use Orbit\Core\Progress\ProgressEvent;
use Orbit\Core\Progress\ProgressEventType;
use RuntimeException;

/**
 * Gateway-side processor for the framed-progress stream emitted by a hidden
 * `internal:*` command over SSH stdout (NDJSON, one frame per line).
 *
 * Responsibilities:
 *   - Decode each line via {@see InternalProgressFrameDecoder}.
 *   - Run every frame payload through the shared
 *     {@see ResultBoundaryRedactionPolicy} BEFORE any persistence; reject
 *     forbidden key fragments and PEM-block values fail-closed.
 *   - On `complete` / `error` terminal frames, transition the
 *     `operation_runs` row via {@see OperationRunRecorder}.
 *   - Treat a stream that closes without a terminal frame as a hard error
 *     ({@see InternalProgressStreamClosed}); the caller (eventually
 *     `RemoteLocalExecutor::streamInternal()`) must persist no result.
 *
 * Intermediate `tree` and `step` frames are recognised, redacted, and exposed
 * to callers via a callback so future ORBIT-CLI-10E broadcast hooks can be
 * wired in without changing this processor's persistence contract.
 */
final readonly class InternalProgressStreamProcessor
{
    public function __construct(
        private InternalProgressFrameDecoder $decoder,
        private ResultBoundaryRedactionPolicy $redaction,
        private OperationRunRecorder $recorder,
    ) {}

    /**
     * @param  iterable<string>  $lines  one NDJSON line per element (newline already stripped, or not — both work)
     * @param  null|callable(ProgressEvent): void  $onIntermediate  invoked for every tree/step frame after redaction passes
     */
    public function process(string $operationRunId, iterable $lines, ?callable $onIntermediate = null): OperationRun
    {
        $terminal = null;

        foreach ($lines as $line) {
            $event = $this->decoder->decode($line);

            if ($event === null) {
                continue;
            }

            $this->redaction->assertSafe($event->payload, 'progress');

            if ($event->type === ProgressEventType::Tree || $event->type === ProgressEventType::Step) {
                if ($onIntermediate !== null) {
                    $onIntermediate($event);
                }

                continue;
            }

            $terminal = $event;
            break;
        }

        if ($terminal === null) {
            throw new InternalProgressStreamClosed(
                'internal_progress_stream_closed: stream ended before a complete or error frame arrived.',
            );
        }

        return $this->persistTerminal($operationRunId, $terminal);
    }

    private function persistTerminal(string $operationRunId, ProgressEvent $terminal): OperationRun
    {
        if ($terminal->type === ProgressEventType::Complete) {
            $exitCode = $this->intPayloadField($terminal->payload, 'exit_code', 0);
            $result = $terminal->payload;
            unset($result['exit_code']);

            return $this->recorder->succeeded(
                id: $operationRunId,
                exitCode: $exitCode,
                result: $result === [] ? null : $result,
            );
        }

        // ProgressEventType::Error
        $exitCode = $this->intPayloadField($terminal->payload, 'exit_code', null);
        $error = $terminal->payload;
        unset($error['exit_code']);

        return $this->recorder->failed(
            id: $operationRunId,
            exitCode: $exitCode,
            error: $error === [] ? null : $error,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function intPayloadField(array $payload, string $key, ?int $default): ?int
    {
        $value = $payload[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return $default;
    }
}

final class InternalProgressStreamClosed extends RuntimeException {}
