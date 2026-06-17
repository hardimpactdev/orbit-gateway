<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;

interface RoleBaseline
{
    public function converge(Node $node, NodeRoleAssignment $assignment): void;

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void;
}
