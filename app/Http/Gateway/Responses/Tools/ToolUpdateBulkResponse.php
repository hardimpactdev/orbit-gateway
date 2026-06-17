<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Tools;

final readonly class ToolUpdateBulkResponse
{
    /**
     * @param  list<array<string, mixed>>  $updated
     * @param  list<array<string, mixed>>  $skipped
     * @param  list<array<string, mixed>>  $failed
     */
    public function __construct(
        public array $updated = [],
        public array $skipped = [],
        public array $failed = [],
    ) {}
}
