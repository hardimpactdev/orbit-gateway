<?php

declare(strict_types=1);

namespace App\Support\Streaming;

use App\Contracts\ProgressReporter;

final class NullProgressReporter implements ProgressReporter
{
    public function tree(string $title, array $steps): void
    {
        //
    }

    public function stepStart(string $key): void
    {
        //
    }

    public function stepProgress(string $key, string $status, ?string $message = null): void
    {
        //
    }

    public function stepDone(string $key, ?string $message = null): void
    {
        //
    }

    public function stepFail(string $key, string $message): void
    {
        //
    }

    public function stepSkip(string $key, ?string $message = null): void
    {
        //
    }
}
