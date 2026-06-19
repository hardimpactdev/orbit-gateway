<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workspaces\RemoveWorkspace;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('workspace:remove', servingNode: ServingNode::WorkspaceOwning)]
final class WorkspaceRemoveController implements Loggable
{
    private ?Workspace $activitySubject = null;

    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
    ) {}

    public function __invoke(string $name, Request $request, RemoveWorkspace $removeWorkspace): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->error('validation_failed', 'Use --force to remove this workspace.', ['field' => 'force'], 422);
        }

        $app = $this->stringQuery($request, 'app');
        $matches = $this->matchingWorkspaces($name, $app);

        if ($matches->isEmpty()) {
            return $this->error('workspace.not_found', "Workspace '{$name}' not found in registry.", array_filter([
                'name' => $name,
                'app' => $app,
            ], fn (?string $value): bool => $value !== null), 404);
        }

        if ($app === null && $matches->count() > 1) {
            return $this->error('workspace.ambiguous_name', "Workspace name '{$name}' matches multiple apps.", [
                'name' => $name,
            ], 400);
        }

        $workspace = $matches->firstOrFail();

        $node = $workspace->app?->node;

        if (! $node instanceof Node) {
            return $this->error('authorization_failed', 'Workspace owning node could not be resolved.', [
                'name' => $workspace->name,
                'app' => $workspace->app?->name,
            ], 403);
        }

        $authorization = $this->authorizer->authorize($caller, $node, 'workspace:remove');

        if (! $authorization->allowed) {
            return $this->forbidden($node, $authorization, 'workspace:remove');
        }

        $this->activitySubject = $workspace;
        $result = $removeWorkspace->handle(
            workspace: $workspace,
            keepFiles: $request->boolean('keep_files'),
        );
        $meta = [
            'kept_files' => $result['kept_files'],
        ];

        if ($result['warnings'] !== []) {
            $meta['warnings'] = $result['warnings'];
        }

        unset($result['kept_files'], $result['warnings']);

        return response()->json([
            'success' => [
                'data' => $result,
                'meta' => $meta,
            ],
        ]);
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function matchingWorkspaces(string $name, ?string $app): Collection
    {
        return Workspace::query()
            ->with(['app.node', 'app.processes'])
            ->where('name', $name)
            ->when($app !== null, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->get();
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    private function forbidden(Node $servingNode, AuthorizationResult $result, string $permission): JsonResponse
    {
        return $this->error(
            'authorization_failed',
            "This node is not authorized for '{$permission}' on '{$servingNode->name}'.",
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $servingNode->name,
            ],
            403,
        );
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Destructive;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:DELETE /workspaces/{name}';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
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
        return [
            'name' => request()->route('name'),
        ];
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
