<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeShowResponse
{
    /**
     * @param  array<string, mixed>  $node
     */
    public function __construct(
        public array $node,
    ) {}
}
