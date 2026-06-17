<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Nodes;

final readonly class NodeGrantResponse
{
    /**
     * @param  list<array{code: string, family: string, message: string, next_command: string|null, permissions: list<string>}>  $warnings
     */
    public function __construct(
        public string $consumingNode,
        public string $servingNode,
        public bool $alreadyGranted,
        public string $action = 'granted',
        public ?array $permissions = null,
        public array $warnings = [],
    ) {}
}
