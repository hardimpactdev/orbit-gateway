<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\OperationRun;
use App\Services\Operations\OperationEventStreamer;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[RequiresPermission('*', servingNode: ServingNode::Gateway)]
final readonly class OperationEventStreamController
{
    public function __invoke(
        Request $request,
        OperationRun $operationRun,
        OperationEventStreamer $streamer,
        ProgressEventStreamResponseFactory $streams,
    ): StreamedResponse {
        $lastSequence = $this->lastEventSequence($request);
        $pollMicroseconds = $this->pollMicroseconds($request);
        $maxIdlePolls = $this->maxIdlePolls($request);

        return $streams->make(function (ProgressEventStreamEmitter $events) use ($streamer, $operationRun, $lastSequence, $pollMicroseconds, $maxIdlePolls): void {
            foreach ($streamer->follow($operationRun, $lastSequence, $pollMicroseconds, $maxIdlePolls) as $event) {
                if ($event === null) {
                    $events->heartbeat();

                    continue;
                }

                $events->event($event->event_type, $event->payload, $event->sequence);
            }
        });
    }

    private function lastEventSequence(Request $request): ?int
    {
        $value = $request->headers->get('Last-Event-ID');

        if ($value === null) {
            $queryValue = $request->query('last_event_id');
            $value = is_scalar($queryValue) ? (string) $queryValue : null;
        }

        if ($value === null) {
            $queryValue = $request->query('since');
            $value = is_scalar($queryValue) ? (string) $queryValue : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        $lastSequence = (int) $value;

        return $lastSequence > 0 ? $lastSequence : null;
    }

    private function pollMicroseconds(Request $request): int
    {
        $value = $request->query('poll_microseconds');

        if (! is_scalar($value) || ! ctype_digit((string) $value)) {
            return 500_000;
        }

        return max(0, (int) $value);
    }

    private function maxIdlePolls(Request $request): ?int
    {
        if ($request->boolean('once')) {
            return 0;
        }

        $value = $request->query('max_idle_polls');

        if (! is_scalar($value) || ! ctype_digit((string) $value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
