<?php

declare(strict_types=1);

namespace App\Support\Streaming;

use App\Contracts\ProgressReporter;

final readonly class SseProgressReporter implements ProgressReporter
{
    public function __construct(
        private ProgressEventStreamEmitter $emitter,
    ) {}

    public function tree(string $title, array $steps): void
    {
        $this->emitter->tree($title, $steps);
    }

    public function stepStart(string $key): void
    {
        $this->emitter->stepEvent($key, 'start');
    }

    public function stepProgress(string $key, string $status, ?string $message = null): void
    {
        $this->emitter->stepEvent($key, $status, $message);
    }

    public function stepDone(string $key, ?string $message = null): void
    {
        $this->emitter->stepEvent($key, 'done', $message);
    }

    public function stepFail(string $key, string $message): void
    {
        $this->emitter->stepEvent($key, 'fail', $message);
    }

    public function stepSkip(string $key, ?string $message = null): void
    {
        $this->emitter->stepEvent($key, 'skip', $message);
    }
}
