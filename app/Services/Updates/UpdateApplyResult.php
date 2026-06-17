<?php

declare(strict_types=1);

namespace App\Services\Updates;

final readonly class UpdateApplyResult
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public string $driver,
        public string $status,
        public string $summary,
        public array $detail = [],
    ) {}
}
