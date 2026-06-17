<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use RuntimeException;

class GatewayRoleBaseline implements RoleBaseline
{
    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        throw new RuntimeException('The gateway role cannot be converged through normal role assignment.');
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        throw new RuntimeException('The gateway role cannot be removed through normal role assignment.');
    }
}
