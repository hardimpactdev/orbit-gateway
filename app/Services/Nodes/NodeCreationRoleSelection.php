<?php

declare(strict_types=1);

namespace App\Services\Nodes;

final readonly class NodeCreationRoleSelection
{
    /**
     * @param  list<string>  $hosted
     */
    public function __construct(
        public bool $gateway,
        public bool $operator,
        public bool $clientIdentity,
        public array $hosted,
        public ?string $template,
        public ?string $requestedRoleMeta,
    ) {}
}
