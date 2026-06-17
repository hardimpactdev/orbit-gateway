<?php

declare(strict_types=1);

namespace App\Data\RuntimeBackend;

final readonly class RuntimeBackendProbeResult
{
    public function __construct(
        public bool $available,
        public int $exitCode,
        public string $output,
    ) {}
}
