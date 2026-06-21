<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class AgentToolAuthorizer
{
    public function __construct(
        private NodeRoleAssignments $roleAssignments,
        private NodeAccessAuthorizer $authorizer,
        private ToolCatalog $catalog,
    ) {}

    /**
     * Check if the caller is an agent managing its own tools (agent self).
     */
    public function isAgentSelf(Node $caller, ?string $targetNodeName): bool
    {
        if ($targetNodeName === null) {
            return false;
        }

        if (! $this->roleAssignments->nodeHasActiveAgentRole($caller)) {
            return false;
        }

        return $caller->name === $targetNodeName;
    }

    /**
     * Determine if an agent self tool action is authorized.
     *
     * @return array{authorized: bool, reason?: string}
     */
    public function authorizeAgentSelfAction(Node $caller, string $tool, string $action): array
    {
        $category = $this->catalog->category($tool);

        return match ($action) {
            'install' => $this->authorizeAgentSelfWithPermission($caller, $tool, $category, 'tool:install', 'install'),
            'remove' => $this->authorizeAgentSelfWithPermission($caller, $tool, $category, 'tool:remove', 'remove'),
            'reconfigure' => $this->authorizeAgentSelfWithPermission($caller, $tool, $category, 'tool:reconfigure', 'reconfigure'),
            'credentials' => $this->authorizeAgentSelfWithPermission($caller, $tool, $category, 'tool:credentials', 'read tool credentials'),
            'update' => $this->authorizeAgentSelfUpdate($caller, $tool, $category),
            default => [
                'authorized' => false,
                'reason' => "Agent self is not authorized to {$action} tools.",
            ],
        };
    }

    /**
     * @return array{authorized: bool, reason?: string}
     */
    private function authorizeAgentSelfWithPermission(Node $caller, string $tool, ?string $category, string $permission, string $actionLabel): array
    {
        if ($category !== 'agent') {
            return [
                'authorized' => false,
                'reason' => "Agent self may only {$actionLabel} agent-category tools. '{$tool}' is not an agent tool.",
            ];
        }

        if (! $this->authorizer->allows($caller, $caller, $permission)) {
            return [
                'authorized' => false,
                'reason' => "Agent self is not authorized to {$actionLabel} tools.",
            ];
        }

        return ['authorized' => true];
    }

    /**
     * @return array{authorized: bool, reason?: string}
     */
    private function authorizeAgentSelfUpdate(Node $caller, string $tool, ?string $category): array
    {
        if ($category !== 'agent') {
            return [
                'authorized' => false,
                'reason' => "Agent self may only update agent-category tools. '{$tool}' is not an agent tool.",
            ];
        }

        if (! $this->authorizer->allows($caller, $caller, 'tool:update:agent-tools')) {
            return [
                'authorized' => false,
                'reason' => 'Agent self is not authorized to update agent tools.',
            ];
        }

        return ['authorized' => true];
    }

    /**
     * Check if a node has multiple installed agent tools.
     *
     * @return list<string>
     */
    public function runningAgentToolsOnNode(Node $node): array
    {
        return NodeTool::query()
            ->where('node_id', $node->id)
            ->where('expected_state', 'installed')
            ->whereIn('name', $this->agentToolNames())
            ->pluck('name')
            ->all();
    }

    /**
     * @return list<string>
     */
    public function agentToolNames(): array
    {
        return array_values(array_filter(
            $this->catalog->names(),
            fn (string $tool): bool => $this->catalog->category($tool) === 'agent',
        ));
    }

    /**
     * Check if installing an agent tool should emit a multiple installed agent tools warning.
     *
     * @return array{code: string, tools: list<string>}|null
     */
    public function multipleAgentToolsWarning(Node $node, string $tool): ?array
    {
        if ($this->catalog->category($tool) !== 'agent') {
            return null;
        }

        $runningAgentTools = $this->runningAgentToolsOnNode($node);

        if ($runningAgentTools === []) {
            return null;
        }

        // If the tool being installed is already in the installed list, don't warn
        if (in_array($tool, $runningAgentTools, true)) {
            return null;
        }

        return [
            'code' => 'tool.multiple_agent_tools_running',
            'tools' => array_values(array_unique([...$runningAgentTools, $tool])),
        ];
    }
}
