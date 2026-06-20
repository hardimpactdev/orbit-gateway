<?php

declare(strict_types=1);

namespace App\Data\RemoteShell;

use Throwable;

final readonly class RemoteShellPoolResult
{
    public function __construct(
        public string $key,
        public RemoteShellPoolJob $job,
        public ?RemoteShellResult $result = null,
        public ?Throwable $exception = null,
    ) {}

    public function successful(): bool
    {
        return $this->exception === null
            && $this->result?->successful() === true;
    }
}
