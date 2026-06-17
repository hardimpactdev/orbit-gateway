<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class UpdateTargetFactory
{
    public function __construct(private NodeRoleAssignments $roleAssignments) {}

    public function forNode(Node $node): UpdateTarget
    {
        return new UpdateTarget(
            family: 'node',
            node: $node,
            platform: $node->platform,
            scope: $this->isManagedServerNode($node) ? 'managed-server-node' : 'unsupported-node',
        );
    }

    private function isManagedServerNode(Node $node): bool
    {
        if (! in_array($node->platform, ['ubuntu_24-04', 'ubuntu_26-04'], true)) {
            return false;
        }

        return $this->roleAssignments->nodeHasAnyActiveRole($node, [
            NodeRoleName::Gateway->value,
            NodeRoleName::Vpn->value,
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
            NodeRoleName::Database->value,
            NodeRoleName::Agent->value,
        ]);
    }
}
