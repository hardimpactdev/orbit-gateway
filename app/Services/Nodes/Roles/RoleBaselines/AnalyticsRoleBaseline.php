<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Processes\ProcessServiceDefinitionRegistry;
use App\Services\Tools\ToolCatalog;
use RuntimeException;

class AnalyticsRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    private const string ProcessName = 'plausible';

    private const string DefaultVersion = '3.2.2';

    public function __construct(
        private readonly ProcessServiceDefinitionRegistry $definitions,
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if ($this->nodeRoleAssignments()->nodeIsGateway($node)) {
            throw new RuntimeException('The analytics role cannot be assigned to a gateway node.');
        }

        if (! str_starts_with((string) $node->platform, 'ubuntu')) {
            throw new RuntimeException('The analytics role requires an Ubuntu host.');
        }

        $this->convergeTools($node, ['docker']);
        $this->convergePlausibleProcess($node);
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        Process::query()
            ->ownedBy($node)
            ->where('name', self::ProcessName)
            ->where('runtime_config->definition', 'plausible')
            ->delete();

        $this->removeTools($node, ['docker']);
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    private function convergePlausibleProcess(Node $node): void
    {
        $definition = $this->definitions->resolve(
            definition: 'plausible',
            version: self::DefaultVersion,
            runtime: ProcessRuntime::DockerSwarm,
            node: $node,
            processName: self::ProcessName,
        );

        Process::query()->updateOrCreate(
            [
                'owner_type' => $node->getMorphClass(),
                'owner_id' => $node->id,
                'name' => self::ProcessName,
            ],
            [
                'node_id' => $node->id,
                'command' => $definition->command,
                'restart_policy' => ProcessRestartPolicy::Always,
                'crash_notification' => ProcessCrashNotification::AgentIde,
                'runtime' => ProcessRuntime::DockerSwarm,
                'tool' => null,
                'runtime_config' => $definition->runtimeConfig,
                'sort_order' => 10,
            ],
        );
    }
}
