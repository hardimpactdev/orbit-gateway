<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Database;

final readonly class DatabaseConnectionResultResponse
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(
        public array $result,
    ) {}
}
