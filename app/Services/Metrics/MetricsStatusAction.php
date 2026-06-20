<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\Process;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class MetricsStatusAction
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $nodeAccessAuthorizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(Node $caller, ?string $nodeName): array
    {
        if ($nodeName !== null) {
            $node = $this->metricsNode($nodeName);

            if (! $node instanceof Node) {
                return $this->error(
                    'validation_failed',
                    'An active metrics role node is required.',
                    ['field' => 'node', 'required_role' => 'metrics'],
                    422,
                );
            }

            if (! $this->callerCanReadStatus($caller, $node)) {
                return $this->error(
                    'authorization_failed',
                    "This node is not authorized for 'process:read' on '{$node->name}'.",
                    [
                        'reason' => 'missing_permission',
                        'missing_permission' => 'process:read',
                        'serving_node' => $node->name,
                    ],
                    403,
                );
            }

            return $this->success([$this->statusForNode($node)]);
        }

        $nodes = array_values(array_filter(
            $this->metricsNodes(),
            fn (Node $node): bool => $this->callerCanReadStatus($caller, $node),
        ));

        return $this->success(array_map(
            $this->statusForNode(...),
            $nodes,
        ));
    }

    private function metricsNode(string $nodeName): ?Node
    {
        $metricsNodeIds = $this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::Metrics->value);

        if ($metricsNodeIds === []) {
            return null;
        }

        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $metricsNodeIds)
            ->where('name', $nodeName)
            ->first();
    }

    /**
     * @return list<Node>
     */
    private function metricsNodes(): array
    {
        $metricsNodeIds = $this->nodeRoleAssignments->activeNodeIdsForRole(NodeRoleName::Metrics->value);

        if ($metricsNodeIds === []) {
            return [];
        }

        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $metricsNodeIds)
            ->orderBy('name')
            ->get()
            ->all();
    }

    private function callerCanReadStatus(Node $caller, Node $metricsNode): bool
    {
        if ($this->nodeRoleAssignments->nodeIsGateway($caller)) {
            return true;
        }

        return $this->nodeAccessAuthorizer->allows($caller, $metricsNode, 'process:read');
    }

    /**
     * @return array<string, mixed>
     */
    private function statusForNode(Node $node): array
    {
        $processes = Process::query()
            ->where('node_id', $node->id)
            ->where('owner_type', $node->getMorphClass())
            ->where('owner_id', $node->id)
            ->whereIn('name', ['prometheus', 'grafana', 'node-exporter'])
            ->orderBy('name')
            ->get();

        return [
            'node' => $node->name,
            'url' => 'https://metrics.orbit',
            'processes' => $processes
                ->map(fn (Process $process): array => [
                    'name' => $process->name,
                    'runtime' => $process->runtime->value,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     * @return array<string, mixed>
     */
    private function success(array $metrics): array
    {
        return [
            'success' => [
                'data' => [
                    'metrics' => $metrics,
                ],
                'meta' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function error(string $code, string $message, array $meta, int $status): array
    {
        return [
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
                'status' => $status,
            ],
        ];
    }
}
