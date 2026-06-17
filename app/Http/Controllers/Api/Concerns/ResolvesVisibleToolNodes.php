<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\Nodes\NodeStatus;
use App\Models\App;
use App\Models\Node;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Tools\AgentToolAuthorizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait ResolvesVisibleToolNodes
{
    /**
     * @return list<int>
     */
    private function visibleToolNodeIds(Node $caller, bool $allowAnyActiveNode = false, ?string $requiredPermission = null): array
    {
        $requiredPermission ??= 'tool:read';

        if ($this->nodeRoleAssignments()->nodeIsGateway($caller)) {
            $query = Node::query()
                ->where('status', NodeStatus::Active->value);

            if (! $allowAnyActiveNode) {
                $query->whereIn('id', $this->nodeRoleAssignments()->activeToolHostNodeIds());
            }

            return $query->pluck('id')->all();
        }

        $authorizer = app(NodeAccessAuthorizer::class);

        $query = Node::query()
            ->where('status', NodeStatus::Active->value);

        if (! $allowAnyActiveNode) {
            $query->whereIn('id', $this->nodeRoleAssignments()->activeToolHostNodeIds());
        }

        $visibleNodeIds = [];

        foreach ($query->get() as $node) {
            if ($authorizer->allows($caller, $node, $requiredPermission)) {
                $visibleNodeIds[] = $node->id;
            }
        }

        return array_values(array_unique($visibleNodeIds));
    }

    /**
     * @param  list<int>  $visibleNodeIds
     * @return array{node: ?string, app: ?string}|JsonResponse
     */
    private function authorizedToolTarget(
        Request $request,
        Node $caller,
        array $visibleNodeIds,
        bool $allowOnlyVisibleFallback = true,
        bool $allowAnyActiveNode = false,
    ): array|JsonResponse {
        $node = $this->toolTargetString($request, 'node');
        $app = $this->toolTargetString($request, 'app');
        $nodeFilter = null;

        if ($node !== null) {
            $nodeFilter = $this->resolveNodeFilter($node, $caller, $visibleNodeIds, $allowAnyActiveNode);

            if (! $nodeFilter instanceof Node) {
                return $this->toolTargetFailure($node, 'node', $caller, $visibleNodeIds, $allowAnyActiveNode);
            }
        }

        if ($app !== null) {
            $appNode = $this->resolveAppNodeFilter($app, $caller, $visibleNodeIds);

            if (! $appNode instanceof Node) {
                return $this->toolTargetFailure($app, 'app', $caller, $visibleNodeIds);
            }

            if ($nodeFilter instanceof Node && $nodeFilter->id !== $appNode->id) {
                return $this->toolTargetValidationFailed('app', $app, "Invalid value for --app: '{$app}'. App is not owned by the selected node.");
            }

            $nodeFilter = $appNode;
        }

        if ($nodeFilter instanceof Node) {
            return [
                'node' => $node,
                'app' => $app,
            ];
        }

        if ($allowOnlyVisibleFallback && ! $this->nodeRoleAssignments()->nodeIsGateway($caller)) {
            $nodes = Node::query()
                ->whereIn('id', $visibleNodeIds)
                ->whereIn('id', $this->nodeRoleAssignments()->activeToolHostNodeIds())
                ->where('status', NodeStatus::Active->value)
                ->orderBy('name')
                ->limit(2)
                ->get();

            if ($nodes->count() === 1) {
                return [
                    'node' => $nodes->first()->name,
                    'app' => null,
                ];
            }
        }

        return [
            'node' => null,
            'app' => null,
        ];
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function resolveNodeFilter(string $node, Node $caller, array $visibleNodeIds, bool $allowAnyActiveNode = false): ?Node
    {
        $query = Node::query()
            ->where('name', $node)
            ->where('status', NodeStatus::Active->value)
            ->when(! $this->nodeRoleAssignments()->nodeIsGateway($caller), fn (Builder $query): Builder => $query->whereIn('id', $visibleNodeIds));

        if (! $allowAnyActiveNode) {
            $query->whereIn('id', $this->nodeRoleAssignments()->activeToolHostNodeIds());
        }

        return $query->first();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function resolveAppNodeFilter(string $app, Node $caller, array $visibleNodeIds): ?Node
    {
        $model = App::query()
            ->with('node')
            ->when(! $this->nodeRoleAssignments()->nodeIsGateway($caller), fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds))
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
                    ->when(! $this->nodeRoleAssignments()->nodeIsGateway($caller), fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds))
                    ->where('name', $appName)
                    ->whereHas('node', function (Builder $query) use ($nodeTld): void {
                        $query
                            ->whereIn('id', $this->nodeRoleAssignments()->activeAppHostNodeIds())
                            ->where('status', NodeStatus::Active->value)
                            ->where('tld', $nodeTld);
                    })
                    ->first();
            }
        }

        if (! $model instanceof App || ! $model->node instanceof Node) {
            return null;
        }

        if (! $model->node->isActive() || ! $this->nodeRoleAssignments()->nodeHasActiveAppHostRole($model->node)) {
            return null;
        }

        return $model->node;
    }

    private function toolTargetString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function toolTargetFailure(
        string $value,
        string $field,
        Node $caller,
        array $visibleNodeIds,
        bool $allowAnyActiveNode = false,
    ): JsonResponse {
        if (! $this->nodeRoleAssignments()->nodeIsGateway($caller) && $this->toolTargetExists($field, $value, $visibleNodeIds, $allowAnyActiveNode)) {
            return $this->toolTargetAuthorizationFailed("This node is not authorized to manage tools for the selected {$field}.", [
                $field => $value,
            ]);
        }

        $expected = $field === 'node'
            ? ($allowAnyActiveNode ? 'Expected a visible node name.' : 'Expected a visible tool node name.')
            : 'Expected a visible app name or domain.';

        return $this->toolTargetValidationFailed($field, $value, "Invalid value for --{$field}: '{$value}'. {$expected}");
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function toolTargetExists(string $field, string $value, array $visibleNodeIds, bool $allowAnyActiveNode = false): bool
    {
        if ($field === 'node') {
            $query = Node::query()
                ->where('name', $value)
                ->where('status', NodeStatus::Active->value)
                ->whereNotIn('id', $visibleNodeIds);

            if (! $allowAnyActiveNode) {
                $query->whereIn('id', $this->nodeRoleAssignments()->activeToolHostNodeIds());
            }

            return $query->exists();
        }

        return App::query()
            ->where(function (Builder $query) use ($value): void {
                $query->where('name', $value)
                    ->orWhere('domain', $value);

                if (str_contains($value, '.')) {
                    [$appName, $nodeTld] = explode('.', $value, 2);

                    if ($appName !== '' && $nodeTld !== '') {
                        $query->orWhere(function (Builder $query) use ($appName, $nodeTld): void {
                            $query
                                ->where('name', $appName)
                                ->whereHas('node', function (Builder $query) use ($nodeTld): void {
                                    $query->where('tld', $nodeTld);
                                });
                        });
                    }
                }
            })
            ->whereHas('node', function (Builder $query) use ($visibleNodeIds): void {
                $query->whereIn('id', $this->nodeRoleAssignments()->activeAppHostNodeIds())
                    ->where('status', NodeStatus::Active->value)
                    ->whereNotIn('id', $visibleNodeIds);
            })
            ->exists();
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return app(NodeRoleAssignments::class);
    }

    private function isAgentSelf(Node $caller, ?string $targetNodeName): bool
    {
        if ($targetNodeName === null) {
            return false;
        }

        if (! $this->nodeRoleAssignments()->nodeHasActiveAgentRole($caller)) {
            return false;
        }

        return $caller->name === $targetNodeName;
    }

    /**
     * Check agent self authorization for tool actions.
     */
    private function authorizeAgentToolAction(Node $caller, ?string $targetNodeName, string $tool, string $action): ?JsonResponse
    {
        $authorizer = app(AgentToolAuthorizer::class);

        if (! $authorizer->isAgentSelf($caller, $targetNodeName)) {
            return null;
        }

        $result = $authorizer->authorizeAgentSelfAction($caller, $tool, $action);

        if (! $result['authorized']) {
            return $this->toolTargetAuthorizationFailed($result['reason'] ?? 'Agent self is not authorized to perform this action.');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function toolTargetAuthorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], 403);
    }

    private function toolTargetValidationFailed(string $field, string $value, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => [
                    'field' => $field,
                    'value' => $value,
                ],
            ],
        ], 422);
    }
}
