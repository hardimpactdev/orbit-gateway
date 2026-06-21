<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Tools;

final readonly class ToolListResponse
{
    /**
     * @param  list<array<string, mixed>>  $tools
     */
    public function __construct(
        public array $tools,
    ) {}
}
