<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Processes;

final readonly class ProcessListResponse
{
    /**
     * @param  array{app?: string|null, workspace?: string|null}  $context
     * @param  list<array<string, mixed>>  $processes
     */
    public function __construct(
        public array $context,
        public array $processes,
    ) {}
}
