<?php

declare(strict_types=1);

namespace App\Data\RuntimeBackend;

final readonly class GatewayRuntimeBackendProbeResult
{
    public function __construct(
        public string $runtimeStatus,
        public bool $containerExists,
        public bool $containerRunning,
        public int $exitCode,
        public string $output,
    ) {}
}
