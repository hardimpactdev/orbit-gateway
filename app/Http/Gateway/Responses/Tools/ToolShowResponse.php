<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Tools;

final readonly class ToolShowResponse
{
    /**
     * @param  array<string, mixed>  $tool
     */
    public function __construct(
        public array $tool,
    ) {}
}
