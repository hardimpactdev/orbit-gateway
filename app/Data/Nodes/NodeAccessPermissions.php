<?php

declare(strict_types=1);

namespace App\Data\Nodes;

final readonly class NodeAccessPermissions
{
    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $removed
     */
    public function __construct(
        public array $permissions,
        public array $removed = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->permissions === [];
    }

    public function has(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
