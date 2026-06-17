<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Operations;

final readonly class UpdateAllResponse
{
    /**
     * @param  list<array<string, mixed>>  $updates
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public array $updates,
        public array $summary,
    ) {}
}
