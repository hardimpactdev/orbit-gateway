<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\S3\S3ServiceConfigurator;

class S3RoleBaseline implements RoleBaseline
{
    public function __construct(
        private readonly S3ServiceConfigurator $configurator,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        $this->configurator->configure($node, $assignment);
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $this->configurator->remove($node, $purgeData);
    }
}
