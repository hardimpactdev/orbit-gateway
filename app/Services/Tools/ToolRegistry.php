<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Enums\Nodes\NodeStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final readonly class ToolRegistry
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    /**
     * @return Collection<int, NodeTool>
     */
    public function list(?string $node = null, ?string $app = null): Collection
    {
        $targetNode = $this->resolveNodeFilter($node, $app);

        return NodeTool::query()
            ->with('node')
            ->whereHas('node', fn (Builder $query): Builder => $this->visibleToolNodeQuery($query))
            ->when($targetNode instanceof Node, fn (Builder $query): Builder => $query->where('node_id', $targetNode->id))
            ->get()
            ->sort(fn (NodeTool $first, NodeTool $second): int => [
                mb_strtolower((string) $first->node?->name),
                mb_strtolower($first->name),
            ] <=> [
                mb_strtolower((string) $second->node?->name),
                mb_strtolower($second->name),
            ])
            ->values();
    }

    public function show(
        string $tool,
        ?string $node = null,
        ?string $app = null,
        ?string $instance = null,
        ?string $version = null,
    ): NodeTool|ToolRegistryFailure {
        $targetNode = $this->resolveTargetNode($node, $app);

        if ($targetNode instanceof ToolRegistryFailure) {
            return $targetNode;
        }

        $models = NodeTool::query()
            ->with('node')
            ->where('node_id', $targetNode->id)
            ->where('name', $tool)
            ->orderBy('name')
            ->get();

        if ($instance !== null) {
            return ToolRegistryFailure::notFound($tool, $targetNode->name);
        }

        if ($version !== null) {
            $models = $models
                ->filter(fn (NodeTool $model): bool => $model->expected_version === $version)
                ->values();
        }

        if ($models->isEmpty()) {
            return ToolRegistryFailure::notFound($tool, $targetNode->name);
        }

        return $models->first();
    }

    public function validateFilters(?string $node = null, ?string $app = null): ?ToolRegistryFailure
    {
        $nodeFilter = null;

        if ($node !== null) {
            $nodeFilter = $this->resolveNode($node);

            if (! $nodeFilter instanceof Node) {
                return ToolRegistryFailure::validation('node', $node, "Invalid value for --node: '{$node}'. Expected a visible tool node name.");
            }
        }

        if ($app !== null) {
            $appNode = $this->resolveAppNode($app);

            if (! $appNode instanceof Node) {
                return ToolRegistryFailure::validation('app', $app, "Invalid value for --app: '{$app}'. Expected a visible app name, domain, or app.node-tld selector.");
            }

            if ($nodeFilter instanceof Node && $nodeFilter->id !== $appNode->id) {
                return ToolRegistryFailure::validation(
                    'app',
                    $app,
                    "Invalid value for --app: '{$app}'. App is not owned by the selected node.",
                    [
                        'node' => $nodeFilter->name,
                        'resolved_node' => $appNode->name,
                        'reason' => 'target_mismatch',
                    ],
                );
            }
        }

        return null;
    }

    private function resolveTargetNode(?string $node, ?string $app): Node|ToolRegistryFailure
    {
        $validation = $this->validateFilters($node, $app);

        if ($validation instanceof ToolRegistryFailure) {
            return $validation;
        }

        $targetNode = $this->resolveNodeFilter($node, $app);

        if ($targetNode instanceof Node) {
            return $targetNode;
        }

        return ToolRegistryFailure::validation('target', '', 'A node or app target is required. Provide --node or --app.');
    }

    private function resolveNodeFilter(?string $node, ?string $app): ?Node
    {
        if ($node !== null) {
            return $this->resolveNode($node);
        }

        if ($app !== null) {
            return $this->resolveAppNode($app);
        }

        return null;
    }

    private function resolveNode(?string $node): ?Node
    {
        if ($node === null) {
            return null;
        }

        return Node::query()
            ->where('name', $node)
            ->whereIn('id', $this->nodeRoleAssignments->activeToolHostNodeIds())
            ->where('status', NodeStatus::Active->value)
            ->first();
    }

    private function resolveAppNode(?string $app): ?Node
    {
        if ($app === null) {
            return null;
        }

        $model = App::query()
            ->with('node')
            ->where(function (Builder $query) use ($app): void {
                $query->where('name', $app)
                    ->orWhere('domain', $app);
            })
            ->first();

        if (! $model instanceof App && str_contains($app, '.')) {
            [$appName, $nodeTld] = explode('.', $app, 2);

            if ($appName !== '' && $nodeTld !== '') {
                $model = App::query()
                    ->with('node')
                    ->where('name', $appName)
                    ->whereHas('node', function (Builder $query) use ($nodeTld): void {
                        $query
                            ->whereIn('id', $this->nodeRoleAssignments->activeAppHostNodeIds())
                            ->where('status', NodeStatus::Active->value)
                            ->where('tld', $nodeTld);
                    })
                    ->first();
            }
        }

        if (! $model instanceof App || ! $model->node instanceof Node) {
            return null;
        }

        if (! $model->node->isActive() || ! $this->nodeRoleAssignments->nodeHasActiveAppHostRole($model->node)) {
            return null;
        }

        return $model->node;
    }

    private function visibleToolNodeQuery(Builder $query): Builder
    {
        return $query
            ->whereIn('id', $this->nodeRoleAssignments->activeToolHostNodeIds())
            ->where('status', NodeStatus::Active->value);
    }
}
