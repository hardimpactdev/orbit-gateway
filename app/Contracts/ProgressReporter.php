<?php

declare(strict_types=1);

namespace App\Contracts;

interface ProgressReporter
{
    /**
     * @param  list<array{key: string, label: string, doneLabel?: string}>  $steps
     */
    public function tree(string $title, array $steps): void;

    public function stepStart(string $key): void;

    public function stepProgress(string $key, string $status, ?string $message = null): void;

    public function stepDone(string $key, ?string $message = null): void;

    public function stepFail(string $key, string $message): void;

    public function stepSkip(string $key, ?string $message = null): void;
}
