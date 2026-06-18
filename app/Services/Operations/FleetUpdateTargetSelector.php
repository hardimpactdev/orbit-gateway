<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Collection;

final readonly class FleetUpdateTargetSelector
{
    public function __construct(
        private NodeRoleAssignments $roles,
    ) {}

    /**
     * @return Collection<int, Node>
     */
    public function workloadNodes(): Collection
    {
        $gatewayIds = $this->roles->activeNodeIdsForRole(NodeRoleName::Gateway->value);

        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->roles->activeAssignedNodeIds())
            ->when($gatewayIds !== [], fn ($query) => $query->whereNotIn('id', $gatewayIds))
            ->with('roleAssignments')
            ->orderBy('name')
            ->get();
    }
}
