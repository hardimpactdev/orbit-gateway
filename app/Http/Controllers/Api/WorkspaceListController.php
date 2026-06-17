<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class WorkspaceListController implements Loggable
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $authorizer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $app = $this->stringQuery($request, 'app');
        $node = $this->stringQuery($request, 'node');

        if ($this->containsComma($app)) {
            return $this->validationFailed('app', $app, "Unknown app: '{$app}'.");
        }

        if ($this->containsComma($node)) {
            return $this->validationFailed('node', $node, "Unknown node: '{$node}'.");
        }

        $callerIsGateway = $this->nodeRoleAssignments->nodeIsGateway($caller);
        $visibleNodeIds = $this->visibleAppNodeIds($caller, $callerIsGateway);

        if (! $callerIsGateway && $visibleNodeIds === []) {
            return $this->authorizationFailed('This node is not authorized to read the workspace registry.', [
                'reason' => 'missing_permission',
                'missing_permission' => 'workspace:read',
            ]);
        }

        if ($app !== null && ! $this->appFilterIsValid($app, $callerIsGateway, $visibleNodeIds)) {
            return $this->validationFailed('app', $app, "Unknown app: '{$app}'.");
        }

        if ($node !== null && ! $this->nodeFilterIsValid($node, $callerIsGateway, $visibleNodeIds)) {
            return $this->validationFailed('node', $node, "Unknown node: '{$node}'.");
        }

        $workspaces = $this->fetchWorkspaces(
            callerIsGateway: $callerIsGateway,
            visibleNodeIds: $visibleNodeIds,
            app: $app,
            node: $node,
        );

        return response()->json([
            'success' => [
                'data' => [
                    'workspaces' => $this->workspacePayloads($workspaces),
                ],
            ],
        ]);
    }

    /**
     * @return list<int>
     */
    private function visibleAppNodeIds(Node $caller, bool $callerIsGateway): array
    {
        $visibleNodeIds = $this->hostedAppNodeIds();

        if ($callerIsGateway) {
            return $visibleNodeIds;
        }

        return Node::query()
            ->whereIn('id', $visibleNodeIds)
            ->get()
            ->filter(fn (Node $node): bool => $this->authorizer->allows($caller, $node, 'workspace:read'))
            ->map(fn (Node $node): int => $node->id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function appFilterIsValid(string $app, bool $callerIsGateway, array $visibleNodeIds): bool
    {
        return App::query()
            ->where('name', $app)
            ->when(! $callerIsGateway, fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds))
            ->exists();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function nodeFilterIsValid(string $node, bool $callerIsGateway, array $visibleNodeIds): bool
    {
        return Node::query()
            ->where('name', $node)
            ->when(! $callerIsGateway, fn (Builder $query): Builder => $query->whereIn('id', $visibleNodeIds))
            ->whereIn('id', $this->hostedAppNodeIds())
            ->exists();
    }

    /**
     * @return list<int>
     */
    private function hostedAppNodeIds(): array
    {
        return $this->nodeRoleAssignments->activeNodeIdsForRole('app-dev');
    }

    /**
     * @param  list<int>  $visibleNodeIds
     * @return Collection<int, Workspace>
     */
    private function fetchWorkspaces(bool $callerIsGateway, array $visibleNodeIds, ?string $app, ?string $node): Collection
    {
        return Workspace::query()
            ->with(['app.node'])
            ->when(! $callerIsGateway, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds)))
            ->when($app !== null, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->when($node !== null, fn (Builder $query): Builder => $query->whereHas('app.node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->get()
            ->sort(fn (Workspace $first, Workspace $second): int => [
                mb_strtolower((string) $first->app?->node?->name),
                mb_strtolower((string) $first->app?->name),
                mb_strtolower($first->name),
            ] <=> [
                mb_strtolower((string) $second->app?->node?->name),
                mb_strtolower((string) $second->app?->name),
                mb_strtolower($second->name),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, Workspace>  $workspaces
     * @return list<array<string, mixed>>
     */
    private function workspacePayloads(Collection $workspaces): array
    {
        return $workspaces
            ->map(fn (Workspace $workspace): array => [
                'name' => $workspace->name,
                'app' => $workspace->app?->name,
                'node' => $workspace->app?->node?->name,
                'url' => $workspace->url(),
                'lifecycle_status' => $workspace->lifecycle_status->value,
            ])
            ->all();
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function containsComma(?string $value): bool
    {
        return $value !== null && str_contains($value, ',');
    }

    private function validationFailed(string $field, string $value, string $message): JsonResponse
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
        ], 400);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function authorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], 403);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:GET /workspaces';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
