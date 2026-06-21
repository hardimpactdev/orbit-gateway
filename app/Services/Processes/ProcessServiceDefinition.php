<?php

declare(strict_types=1);

namespace App\Services\Processes;

final readonly class ProcessServiceDefinition
{
    /**
     * @param  array<string, mixed>  $runtimeConfig
     */
    public function __construct(
        public string $name,
        public string $versionFamily,
        public string $version,
        public string $command,
        public array $runtimeConfig,
    ) {}
}
