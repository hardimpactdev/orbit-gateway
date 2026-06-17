<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;

final readonly class ProcessOwnerContextResolver
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $authorizer,
    ) {}

    public function resolve(
        ?string $nodeName,
        ?string $appName,
        ?string $workspaceName,
    ): ProcessOwnerContext {
        return $this->resolveWithVisibility(
            nodeName: $nodeName,
            appName: $appName,
            workspaceName: $workspaceName,
            caller: null,
            permission: null,
            allowSingleVisibleAppDefault: false,
        );
    }

    public function resolveVisible(
        ?string $nodeName,
        ?string $appName,
        ?string $workspaceName,
        ?Node $caller,
        string $permission,
        bool $allowSingleVisibleAppDefault = false,
    ): ProcessOwnerContext {
        return $this->resolveWithVisibility(
            nodeName: $nodeName,
            appName: $appName,
            workspaceName: $workspaceName,
            caller: $caller,
            permission: $permission,
            allowSingleVisibleAppDefault: $allowSingleVisibleAppDefault,
        );
    }

    private function resolveWithVisibility(
        ?string $nodeName,
        ?string $appName,
        ?string $workspaceName,
        ?Node $caller,
        ?string $permission,
        bool $allowSingleVisibleAppDefault,
    ): ProcessOwnerContext {
        $visibleNodeIds = $permission === null
            ? null
            : $this->visibleNodeIds($caller, $permission);

        if ($permission !== null && $caller instanceof Node && ! $this->nodeRoleAssignments->nodeIsGateway($caller) && $visibleNodeIds === []) {
            throw new GatewayApiException('This node is not authorized to read process intent.', 'authorization_failed', [
                'reason' => 'missing_permission',
                'missing_permission' => $permission,
            ]);
        }

        if ($nodeName !== null) {
            if ($appName !== null || $workspaceName !== null) {
                throw new GatewayApiException('A node context cannot be combined with app or workspace context.', 'validation_failed', [
                    'field' => 'context',
                    'node' => $nodeName,
                    'app' => $appName,
                    'workspace' => $workspaceName,
                ]);
            }

            return $this->resolveNode($nodeName, $visibleNodeIds);
        }

        if ($workspaceName !== null) {
            return $this->resolveWorkspace($workspaceName, $appName, $visibleNodeIds);
        }

        if ($appName !== null) {
            return $this->resolveApp($appName, $visibleNodeIds);
        }

        if ($allowSingleVisibleAppDefault) {
            $apps = $this->visibleApps($visibleNodeIds)->get();

            if ($apps->count() === 1) {
                $app = $apps->firstOrFail();
                $app->loadMissing('node');

                return $this->contextForApp($app);
            }
        }

        throw new GatewayApiException('A node, app, or workspace context is required.', 'validation_failed', [
            'field' => 'app',
        ]);
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     */
    private function resolveNode(string $nodeName, ?array $visibleNodeIds): ProcessOwnerContext
    {
        $node = Node::query()
            ->where('name', $nodeName)
            ->when($visibleNodeIds !== null, fn (Builder $query): Builder => $query->whereIn('id', $visibleNodeIds))
            ->first();

        if (! $node instanceof Node) {
            throw new GatewayApiException("Node '{$nodeName}' not found or not visible.", 'validation_failed', [
                'field' => 'node',
                'value' => $nodeName,
            ]);
        }

        return new ProcessOwnerContext(
            node: $node,
            app: null,
            workspace: null,
            owner: $node,
        );
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     */
    private function resolveApp(string $appName, ?array $visibleNodeIds): ProcessOwnerContext
    {
        $app = $this->visibleApps($visibleNodeIds)
            ->where('name', $appName)
            ->first();

        if (! $app instanceof App) {
            throw new GatewayApiException("App '{$appName}' not found or not visible.", 'validation_failed', [
                'field' => 'app',
                'value' => $appName,
            ]);
        }

        return $this->contextForApp($app);
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     */
    private function resolveWorkspace(string $workspaceName, ?string $appName, ?array $visibleNodeIds): ProcessOwnerContext
    {
        $matches = Workspace::query()
            ->with('app.node')
            ->where('name', $workspaceName)
            ->when($appName !== null, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->where('name', $appName)))
            ->when($visibleNodeIds !== null, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds)))
            ->get();

        if ($matches->isEmpty()) {
            throw new GatewayApiException("Workspace '{$workspaceName}' not found or not visible.", 'validation_failed', [
                'field' => 'workspace',
                'value' => $workspaceName,
            ]);
        }

        if ($appName === null && $matches->count() > 1) {
            throw new GatewayApiException("Workspace name '{$workspaceName}' is ambiguous.", 'validation_failed', [
                'field' => 'workspace',
                'value' => $workspaceName,
                'apps' => $matches
                    ->map(fn (Workspace $workspace): ?string => $workspace->app?->name)
                    ->filter()
                    ->values()
                    ->all(),
            ]);
        }

        $workspace = $matches->firstOrFail();
        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new GatewayApiException("Workspace '{$workspaceName}' is not attached to an app.", 'validation_failed', [
                'field' => 'workspace',
                'value' => $workspaceName,
            ]);
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new GatewayApiException("Workspace '{$workspaceName}' app has no node.", 'validation_failed', [
                'field' => 'workspace',
                'value' => $workspaceName,
            ]);
        }

        return new ProcessOwnerContext(
            node: $node,
            app: $app,
            workspace: $workspace,
            owner: $workspace,
        );
    }

    private function contextForApp(App $app): ProcessOwnerContext
    {
        $app->loadMissing('node');

        if (! $app->node instanceof Node) {
            throw new GatewayApiException("App '{$app->name}' has no node.", 'validation_failed', [
                'field' => 'app',
                'value' => $app->name,
            ]);
        }

        return new ProcessOwnerContext(
            node: $app->node,
            app: $app,
            workspace: null,
            owner: $app,
        );
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     * @return Builder<App>
     */
    private function visibleApps(?array $visibleNodeIds): Builder
    {
        return App::query()
            ->with(['node', 'processes'])
            ->when($visibleNodeIds !== null, fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds));
    }

    /**
     * @return list<int>|null
     */
    private function visibleNodeIds(?Node $caller, string $permission): ?array
    {
        if (! $caller instanceof Node || $this->nodeRoleAssignments->nodeIsGateway($caller)) {
            return null;
        }

        $candidateNodeIds = array_values(array_unique([
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-dev'),
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-prod'),
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('agent'),
        ]));

        return Node::query()
            ->whereIn('id', $candidateNodeIds)
            ->get()
            ->filter(fn (Node $node): bool => $this->authorizer->allows($caller, $node, $permission))
            ->map(fn (Node $node): int => $node->id)
            ->values()
            ->all();
    }
}
