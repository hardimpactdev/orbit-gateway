<?php

declare(strict_types=1);

namespace App\Services\Operations;

use JsonException;
use Orbit\Core\Progress\ProgressEvent;
use Orbit\Core\Progress\ProgressEventType;
use RuntimeException;

/**
 * Decodes one NDJSON line emitted by a hidden `internal:*` command running on a
 * remote node. Internal commands MUST emit one JSON object per line of stdout
 * with the shape `{"event": "<type>", "data": {...}}`. The gateway is the only
 * actor that parses this stream — internal commands never call back to the
 * gateway for progress and never broadcast directly to operator clients.
 *
 * Lines that are pure whitespace or start with `#` are treated as comments and
 * silently skipped, mirroring the SSE keepalive convention but for NDJSON. Any
 * line that is non-empty/non-comment but is not a valid framed event is a
 * protocol violation: the decoder fails closed and the caller must NOT persist
 * any prior payload from the same stream.
 */
final class InternalProgressFrameDecoder
{
    /**
     * @return ProgressEvent|null null when the line is a comment/keepalive.
     */
    public function decode(string $line): ?ProgressEvent
    {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "internal_progress_frame_malformed: line is not valid JSON ({$exception->getMessage()}).",
                previous: $exception,
            );
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException('internal_progress_frame_malformed: line must decode to a JSON object.');
        }

        $event = $decoded['event'] ?? null;

        if (! is_string($event) || trim($event) === '') {
            throw new RuntimeException('internal_progress_frame_malformed: missing required "event" key.');
        }

        $type = ProgressEventType::tryFrom($event);

        if (! $type instanceof ProgressEventType) {
            throw new RuntimeException("internal_progress_frame_malformed: unknown event type '{$event}'.");
        }

        $payload = $decoded['data'] ?? [];

        if (! is_array($payload)) {
            throw new RuntimeException('internal_progress_frame_malformed: "data" must be an object or omitted.');
        }

        /** @var array<string, mixed> $payload */
        return new ProgressEvent(type: $type, payload: $payload);
    }
}
