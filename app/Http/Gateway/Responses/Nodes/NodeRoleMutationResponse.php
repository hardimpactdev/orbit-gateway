<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeRoleMutationResponse
{
    /**
     * @param  array<string, mixed>  $assignment
     */
    public function __construct(
        public string $node,
        public array $assignment = [],
        public ?string $removedRole = null,
        public ?bool $purgedData = null,
    ) {}
}
