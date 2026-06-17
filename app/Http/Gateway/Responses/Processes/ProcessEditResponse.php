<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Processes;

final readonly class ProcessEditResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $warnings
     */
    public function __construct(
        public array $data,
        public array $warnings,
    ) {}
}
