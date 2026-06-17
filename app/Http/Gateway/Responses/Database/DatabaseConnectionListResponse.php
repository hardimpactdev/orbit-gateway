<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Database;

final readonly class DatabaseConnectionListResponse
{
    /**
     * @param  list<array<string, mixed>>  $connections
     */
    public function __construct(
        public array $connections,
        public int $count,
    ) {}
}
