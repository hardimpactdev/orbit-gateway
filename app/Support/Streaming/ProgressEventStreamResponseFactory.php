<?php

declare(strict_types=1);

namespace App\Support\Streaming;

use App\Contracts\ProgressReporter;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class ProgressEventStreamResponseFactory
{
    public function __construct(
        private string $sapi = PHP_SAPI,
    ) {}

    /**
     * @param  callable(ProgressEventStreamEmitter): void  $streamer
     */
    public function make(callable $streamer): StreamedResponse
    {
        return new StreamedResponse(function () use ($streamer): void {
            $emitter = new ProgressEventStreamEmitter($this->sapi);

            app()->instance(ProgressReporter::class, new SseProgressReporter($emitter));

            try {
                $streamer($emitter);
            } catch (Throwable $e) {
                Log::error('Progress stream crashed: '.$e->getMessage(), ['exception' => $e]);
                $emitter->error($e->getMessage());
            } finally {
                app()->instance(ProgressReporter::class, new NullProgressReporter);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
