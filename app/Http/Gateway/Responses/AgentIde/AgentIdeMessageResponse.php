<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\AgentIde;

final readonly class AgentIdeMessageResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}
}
