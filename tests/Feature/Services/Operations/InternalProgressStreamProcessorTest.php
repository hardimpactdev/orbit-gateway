<?php

declare(strict_types=1);

use App\Models\OperationRun;
use App\Services\Operations\InternalProgressFrameDecoder;
use App\Services\Operations\InternalProgressStreamClosed;
use App\Services\Operations\InternalProgressStreamProcessor;
use App\Services\Operations\OperationPayloadRejected;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\ResultBoundaryRedactionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;
use Orbit\Core\Progress\ProgressEvent;
use Orbit\Core\Progress\ProgressEventType;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->recorder = app(OperationRunRecorder::class);
    $this->processor = new InternalProgressStreamProcessor(
        new InternalProgressFrameDecoder,
        new ResultBoundaryRedactionPolicy,
        $this->recorder,
    );

    $this->run = $this->recorder->queued((string) Str::uuid(), 'local', internalCommand: 'internal:workspace-adapter:lookup');
});

/**
 * @return list<string>
 */
function ndjsonLines(string $blob): array
{
    return explode("\n", trim($blob));
}

describe('InternalProgressFrameDecoder', function (): void {
    it('skips blank lines and comment lines silently', function (): void {
        $decoder = new InternalProgressFrameDecoder;

        expect($decoder->decode(''))->toBeNull()
            ->and($decoder->decode('   '))->toBeNull()
            ->and($decoder->decode('# heartbeat'))->toBeNull();
    });

    it('decodes each canonical event type', function (string $type): void {
        $decoder = new InternalProgressFrameDecoder;
        $event = $decoder->decode(json_encode(['event' => $type, 'data' => ['x' => 1]], JSON_THROW_ON_ERROR));

        expect($event)->toBeInstanceOf(ProgressEvent::class)
            ->and($event->type->value)->toBe($type)
            ->and($event->payload)->toBe(['x' => 1]);
    })->with(['tree', 'step', 'complete', 'error']);

    it('rejects lines that are not valid JSON', function (): void {
        $decoder = new InternalProgressFrameDecoder;

        expect(fn () => $decoder->decode('{not json}'))
            ->toThrow(RuntimeException::class, 'internal_progress_frame_malformed');
    });

    it('rejects JSON that is not an object', function (): void {
        $decoder = new InternalProgressFrameDecoder;

        expect(fn () => $decoder->decode('["array"]'))
            ->toThrow(RuntimeException::class, 'decode to a JSON object');
    });

    it('rejects frames missing the event key', function (): void {
        $decoder = new InternalProgressFrameDecoder;

        expect(fn () => $decoder->decode('{"data":{}}'))
            ->toThrow(RuntimeException::class, 'missing required "event" key');
    });

    it('rejects frames with unknown event types', function (): void {
        $decoder = new InternalProgressFrameDecoder;

        expect(fn () => $decoder->decode('{"event":"bogus","data":{}}'))
            ->toThrow(RuntimeException::class, "unknown event type 'bogus'");
    });

    it('rejects frames whose data is not an object', function (): void {
        $decoder = new InternalProgressFrameDecoder;

        expect(fn () => $decoder->decode('{"event":"step","data":"not-an-object"}'))
            ->toThrow(RuntimeException::class, '"data" must be an object or omitted');
    });

    it('accepts frames with no data payload', function (): void {
        $decoder = new InternalProgressFrameDecoder;
        $event = $decoder->decode('{"event":"step"}');

        expect($event?->type)->toBe(ProgressEventType::Step)
            ->and($event?->payload)->toBe([]);
    });
});

describe('InternalProgressStreamProcessor', function (): void {
    it('transitions an operation_run row to succeeded on a complete frame and persists the redacted result', function (): void {
        $intermediates = [];
        $lines = [
            '{"event":"tree","data":{"steps":["clone","install"]}}',
            '{"event":"step","data":{"name":"clone","status":"started"}}',
            '{"event":"step","data":{"name":"clone","status":"succeeded","duration_ms":1234}}',
            '{"event":"complete","data":{"exit_code":0,"workspace_id":"docs"}}',
        ];

        $row = $this->processor->process($this->run->id, $lines, function (ProgressEvent $event) use (&$intermediates): void {
            $intermediates[] = $event;
        });

        expect($row->status)->toBe(OperationStatus::Succeeded)
            ->and($row->exit_code)->toBe(0)
            ->and($row->result)->toMatchArray(['workspace_id' => 'docs'])
            ->and($row->finished_at)->not->toBeNull()
            ->and($intermediates)->toHaveCount(3)
            ->and($intermediates[0]->type)->toBe(ProgressEventType::Tree)
            ->and($intermediates[1]->type)->toBe(ProgressEventType::Step);
    });

    it('transitions an operation_run row to failed on an error frame and persists the redacted error', function (): void {
        $lines = [
            '{"event":"step","data":{"name":"clone","status":"started"}}',
            '{"event":"error","data":{"exit_code":17,"code":"clone_failed","message":"remote refused"}}',
        ];

        $row = $this->processor->process($this->run->id, $lines);

        expect($row->status)->toBe(OperationStatus::Failed)
            ->and($row->exit_code)->toBe(17)
            ->and($row->error)->toMatchArray([
                'code' => 'clone_failed',
                'message' => 'remote refused',
            ])
            ->and($row->finished_at)->not->toBeNull();
    });

    it('rejects malformed frames before any persistence and leaves the queued row untouched', function (): void {
        $lines = [
            '{"event":"step","data":{"name":"clone"}}',
            '{not json}',
            '{"event":"complete","data":{"exit_code":0}}',
        ];

        try {
            $this->processor->process($this->run->id, $lines);
            test()->fail('Expected malformed-frame error.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toContain('internal_progress_frame_malformed');
        }

        expect(OperationRun::query()->find($this->run->id)->status)->toBe(OperationStatus::Queued);
    });

    it('rejects any frame whose payload contains a forbidden secret key BEFORE persisting', function (): void {
        $lines = [
            '{"event":"step","data":{"name":"clone","leaked_password":"oops"}}',
            '{"event":"complete","data":{"exit_code":0}}',
        ];

        try {
            $this->processor->process($this->run->id, $lines);
            test()->fail('Expected OperationPayloadRejected for forbidden key in progress frame.');
        } catch (OperationPayloadRejected $exception) {
            expect($exception->errorCode)->toBe('operation.progress_unsafe')
                ->and($exception->meta['reason'])->toBe('forbidden_key');
        }

        expect(OperationRun::query()->find($this->run->id)->status)->toBe(OperationStatus::Queued);
    });

    it('rejects PEM-block values in any progress frame BEFORE persisting', function (): void {
        $pem = "-----BEGIN OPENSSH PRIVATE KEY-----\nMIIB\n-----END OPENSSH PRIVATE KEY-----";
        $lines = [
            '{"event":"complete","data":'.json_encode(['workspace_id' => $pem], JSON_THROW_ON_ERROR).'}',
        ];

        try {
            $this->processor->process($this->run->id, $lines);
            test()->fail('Expected OperationPayloadRejected for PEM block in progress frame.');
        } catch (OperationPayloadRejected $exception) {
            expect($exception->errorCode)->toBe('operation.progress_unsafe')
                ->and($exception->meta['reason'])->toBe('pem_block_value');
        }

        expect(OperationRun::query()->find($this->run->id)->status)->toBe(OperationStatus::Queued);
    });

    it('treats a stream that closes before a terminal frame as InternalProgressStreamClosed and leaves the row untouched', function (): void {
        $lines = [
            '{"event":"step","data":{"name":"clone","status":"started"}}',
            '# stream ended unexpectedly',
        ];

        expect(fn () => $this->processor->process($this->run->id, $lines))
            ->toThrow(InternalProgressStreamClosed::class, 'internal_progress_stream_closed');

        expect(OperationRun::query()->find($this->run->id)->status)->toBe(OperationStatus::Queued);
    });

    it('skips blank lines and # comment heartbeats without consuming a terminal frame', function (): void {
        $lines = ndjsonLines(<<<'NDJSON'

        # heartbeat
        {"event":"step","data":{"name":"clone"}}
        # another heartbeat
        {"event":"complete","data":{"exit_code":0}}
        NDJSON);

        $row = $this->processor->process($this->run->id, $lines);

        expect($row->status)->toBe(OperationStatus::Succeeded);
    });

    it('requires no node-originated callback to the gateway during streaming (processor only consumes stdout lines)', function (): void {
        // The processor signature only depends on an iterable<string>. There is no HTTP
        // client, queue, or broadcaster injected; the boundary is purely the SSH stdout
        // stream the gateway already collects via RemoteLocalExecutor's transport.
        $reflection = new ReflectionMethod(InternalProgressStreamProcessor::class, 'process');

        $parameters = array_map(
            static fn (ReflectionParameter $p): string => (string) $p->getType(),
            $reflection->getParameters(),
        );

        expect($parameters)->toBe(['string', 'iterable', '?callable']);
    });
});
