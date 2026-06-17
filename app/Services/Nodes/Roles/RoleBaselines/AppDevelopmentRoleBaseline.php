<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Tools\ToolCatalog;
use RuntimeException;

class AppDevelopmentRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    public function __construct(
        private readonly DevelopmentDnsMappingEnactor $developmentDnsMappingEnactor = new DevelopmentDnsMappingEnactor,
        private readonly ?ToolCatalog $toolCatalog = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        $tld = $assignment->settings['tld'] ?? null;

        if (! is_string($tld) || ! $this->isValidTld(trim($tld))) {
            throw new RuntimeException('The app-dev role requires a valid tld setting.');
        }

        $result = $this->developmentDnsMappingEnactor->convergeDevelopmentRole($node, $tld);

        if (($result['status'] ?? null) !== 'not_applicable') {
            $this->removeTools($node, ['php']);
            $this->convergeTools($node, ['caddy']);
            $this->convergeTool($node, 'php-cli', 'installed');
            $this->convergeTool($node, 'composer', 'installed');
            $this->convergeTool($node, 'gh', 'installed');
            $this->convergeTool($node, 'laravel-installer', 'installed');

            return;
        }

        throw new RuntimeException('The app-dev role requires a WireGuard address so the development DNS mapping can be materialized.');
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $tld = $assignment->settings['tld'] ?? null;

        if (! is_string($tld) || ! $this->isValidTld(trim($tld))) {
            return;
        }

        $result = $this->developmentDnsMappingEnactor->removeDevelopmentRole($node, $tld);

        if (($result['status'] ?? null) !== 'failed') {
            $this->removeTools($node, ['caddy', 'php', 'php-cli', 'composer', 'gh', 'laravel-installer']);

            return;
        }

        $reason = $result['reason'] ?? 'Failed to remove development DNS mapping.';

        throw new RuntimeException(is_string($reason) ? $reason : 'Failed to remove development DNS mapping.');
    }

    private function isValidTld(string $tld): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld);
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }
}
