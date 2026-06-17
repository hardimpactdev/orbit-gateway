<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Contracts\ProgressReporter;
use App\Http\Gateway\GatewayApiException;
use App\Services\Tools\ToolRegistryFailure;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use App\Support\Tools\ToolActionProgressRunner;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

trait StreamsToolActionProgress
{
    /**
     * @param  callable(): mixed  $operation
     * @param  callable(array<string, mixed>): array<string, mixed>  $data
     * @param  callable(array<string, mixed>): int  $exitCode
     */
    private function streamToolAction(
        ProgressEventStreamResponseFactory $streams,
        string $title,
        string $doneFooter,
        string $failFooter,
        callable $operation,
        callable $data,
        callable $exitCode,
    ): StreamedResponse {
        return $streams->make(function (ProgressEventStreamEmitter $events) use ($title, $doneFooter, $failFooter, $operation, $data, $exitCode): void {
            try {
                $result = app(ToolActionProgressRunner::class)->run(
                    reporter: app(ProgressReporter::class),
                    title: $title,
                    operation: $operation,
                );
            } catch (Throwable $exception) {
                $events->error($exception->getMessage(), 1, [
                    'code' => 'tool.action_failed',
                    'message' => $exception->getMessage(),
                    'meta' => [],
                    'footer' => $failFooter,
                ]);

                return;
            }

            if ($result instanceof ToolRegistryFailure) {
                $events->error($result->message, 1, [
                    'code' => $result->code,
                    'message' => $result->message,
                    'meta' => $result->meta,
                    'footer' => $failFooter,
                ]);

                return;
            }

            if ($result instanceof GatewayApiException) {
                $message = $result->getMessage() !== ''
                    ? $result->getMessage()
                    : 'Gateway connection is required to manage tools.';

                $events->error($message, 1, [
                    'code' => $result->errorCode() ?? 'gateway_unavailable',
                    'message' => $message,
                    'meta' => $result->errorMeta(),
                    'footer' => $failFooter,
                ]);

                return;
            }

            $payload = $data($result);
            $commandExitCode = $exitCode($result);
            $payload['footer'] = $commandExitCode === 0 ? $doneFooter : $failFooter;

            $events->complete($commandExitCode, $payload);
        });
    }

    private function wantsEventStream(Request $request): bool
    {
        return in_array('text/event-stream', $request->getAcceptableContentTypes(), true);
    }
}
