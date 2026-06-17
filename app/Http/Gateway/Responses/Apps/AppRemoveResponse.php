<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Apps;

final readonly class AppRemoveResponse
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
