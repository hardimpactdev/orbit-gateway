<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Tools\ToolCatalog;
use RuntimeException;

class AppProductionRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    public function __construct(
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if ($this->nodeRoleAssignments()->nodeIsGateway($node)) {
            throw new RuntimeException('The app-prod role cannot be assigned to a gateway node.');
        }

        if (! str_starts_with((string) $node->platform, 'ubuntu')) {
            throw new RuntimeException('The app-prod role requires an Ubuntu host.');
        }

        if (! is_string($node->host) || trim($node->host) === '') {
            throw new RuntimeException('The app-prod role requires a reachable host record.');
        }

        $this->removeTools($node, ['php']);
        $this->convergeTools($node, ['caddy']);
        $this->convergeTool($node, 'php-cli', 'installed');
        $this->convergeTool($node, 'composer', 'installed');
        $this->convergeTool($node, 'gh', 'installed');
        $this->convergeTool($node, 'laravel-installer', 'installed');
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $this->removeTools($node, ['caddy', 'php', 'php-cli', 'composer', 'gh', 'laravel-installer']);
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }
}
