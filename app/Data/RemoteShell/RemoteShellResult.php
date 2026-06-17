<?php

declare(strict_types=1);

namespace App\Data\RemoteShell;

final readonly class RemoteShellResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public int $durationMs,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function output(): string
    {
        return collect([$this->stdout, $this->stderr])
            ->filter(fn (string $output): bool => $output !== '')
            ->implode('');
    }

    public function errorOutput(): string
    {
        return $this->stderr;
    }
}
