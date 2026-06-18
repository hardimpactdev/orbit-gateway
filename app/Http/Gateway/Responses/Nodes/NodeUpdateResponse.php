<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeUpdateResponse
{
    /**
     * @param  list<string>  $changed
     * @param  list<array<string, string>>  $warnings
     */
    public function __construct(
        public string $name,
        public array $changed,
        public array $warnings = [],
    ) {}
}
