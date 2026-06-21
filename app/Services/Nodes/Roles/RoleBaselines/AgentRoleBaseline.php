<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Contracts\RemoteShell;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Tools\ToolCatalog;
use RuntimeException;

class AgentRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    public function __construct(
        private readonly DevelopmentDnsMappingEnactor $developmentDnsMappingEnactor = new DevelopmentDnsMappingEnactor,
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
        private readonly ?RemoteShell $remoteShell = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if ($this->nodeRoleAssignments()->nodeIsGateway($node)) {
            throw new RuntimeException('The agent role cannot be assigned to a gateway node.');
        }

        if (! str_starts_with((string) $node->platform, 'ubuntu')) {
            throw new RuntimeException('The agent role requires an Ubuntu host.');
        }

        $tld = $assignment->settings['tld'] ?? null;

        if (! is_string($tld) || ! $this->isValidTld(trim($tld))) {
            throw new RuntimeException('The agent role requires a valid tld setting.');
        }

        $result = $this->developmentDnsMappingEnactor->convergeDevelopmentRole($node, $tld);

        if (($result['status'] ?? null) === 'not_applicable') {
            throw new RuntimeException('The agent role requires a WireGuard address so the agent DNS mapping can be materialized.');
        }

        $this->convergeAgentUser($node);
        $this->convergeTools($node, ['caddy']);
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $tld = $assignment->settings['tld'] ?? null;

        if (is_string($tld) && $this->isValidTld(trim($tld))) {
            $result = $this->developmentDnsMappingEnactor->removeDevelopmentRole($node, $tld);

            if (($result['status'] ?? null) === 'failed') {
                $reason = $result['reason'] ?? 'Failed to remove agent DNS mapping.';

                throw new RuntimeException(is_string($reason) ? $reason : 'Failed to remove agent DNS mapping.');
            }
        }

        $this->removeTools($node, ['caddy']);
    }

    private function convergeAgentUser(Node $node): void
    {
        $shell = $this->remoteShell ?? app(RemoteShell::class);

        $shell->run($node, 'id -u agent >/dev/null 2>&1 || sudo useradd --create-home --shell /bin/bash agent', ['throw' => true]);
        $shell->run($node, 'sudo passwd -l agent >/dev/null 2>&1 || true', ['throw' => true]);
    }

    private function isValidTld(string $tld): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld);
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
