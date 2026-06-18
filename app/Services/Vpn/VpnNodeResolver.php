<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class VpnNodeResolver
{
    public function __construct(
        private NodeRoleAssignments $assignments,
    ) {}

    public function activeVpnNode(): Node
    {
        $node = $this->assignments
            ->activeVpnNodeQuery()
            ->orderBy('id')
            ->first();

        if (! $node instanceof Node) {
            throw new ActiveVpnNodeUnavailable;
        }

        return $node;
    }
}
