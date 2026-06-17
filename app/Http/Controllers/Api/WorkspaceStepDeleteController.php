<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workspaces\RemoveWorkspaceStep;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\WorkspaceLifecyclePhase;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use App\Services\Nodes\Access\AuthorizationResult;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Workspaces\WorkspaceRoleGuard;
use App\Services\Workspaces\WorkspaceStepListPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('workspace:write', servingNode: ServingNode::AppOwning)]
final class WorkspaceStepDeleteController implements Loggable
{
    private ?WorkspaceStep $activitySubject = null;

    public function __construct(
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly WorkspaceRoleGuard $workspaceRoleGuard,
        private readonly RemoveWorkspaceStep $removeWorkspaceStep,
    ) {}

    public function __invoke(string $phase, int $step, Request $request, WorkspaceStepListPayload $payload): JsonResponse
    {
        $phaseEnum = WorkspaceLifecyclePhase::tryFrom($phase);

        if (! $phaseEnum instanceof WorkspaceLifecyclePhase) {
            return $this->validationFailed('phase', 'Unsupported workspace step phase.');
        }

        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->validationFailed('force', 'Use --force to remove this workspace step.');
        }

        $appSlug = $this->stringValue($request, 'app');
        $path = $this->stringValue($request, 'path');

        if ($appSlug === null && $path === null) {
            return $this->validationFailed('app', 'Could not resolve parent app.', [
                'reason' => 'missing_required_input',
            ]);
        }

        $app = $appSlug !== null
            ? $this->resolveAppBySlug($appSlug)
            : $this->resolveAppByPath((string) $path);

        if (! $app instanceof App) {
            return $this->appNotFound($appSlug ?? (string) $path);
        }

        try {
            $this->workspaceRoleGuard->ensureAppSupportsWorkspaces($app);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            return $this->workspaceUnsupportedForProduction($exception);
        }

        $app->loadMissing('node');

        if (! $app->node instanceof Node) {
            return $this->authorizationFailed("Could not resolve owning node for app '{$app->name}'.", [
                'app' => $app->name,
            ]);
        }

        $authorization = $this->authorizer->authorize($caller, $app->node, 'workspace:write');

        if (! $authorization->allowed) {
            return $this->forbidden($app->node, $authorization, 'workspace:write');
        }

        $model = WorkspaceStep::query()
            ->where('app_id', $app->id)
            ->where('phase', $phaseEnum)
            ->whereKey($step)
            ->first();

        if (! $model instanceof WorkspaceStep) {
            return $this->stepNotFound($step, $app->name, $phaseEnum);
        }

        $removed = $payload->forStep($model);
        $this->activitySubject = $model;

        $this->removeWorkspaceStep->handle($model);

        $remaining = WorkspaceStep::query()
            ->where('app_id', $app->id)
            ->where('phase', $phaseEnum)
            ->count();

        return response()->json([
            'success' => [
                'data' => [
                    'result' => ['action' => 'removed'],
                    'step' => $removed,
                ],
                'meta' => [
                    'remaining_step_count' => $remaining,
                    'new_step_count' => $remaining,
                ],
            ],
        ]);
    }

    private function resolveAppBySlug(string $slug): ?App
    {
        return App::query()->where('name', $slug)->first();
    }

    private function resolveAppByPath(string $path): ?App
    {
        $normalizedPath = rtrim($path, '/');

        $app = App::query()
            ->get()
            ->first(function (App $app) use ($normalizedPath): bool {
                $appPath = rtrim($app->path, '/');

                return $normalizedPath === $appPath || str_starts_with($normalizedPath, "{$appPath}/");
            });

        if ($app instanceof App) {
            return $app;
        }

        return Workspace::query()
            ->with('app')
            ->get()
            ->first(function (Workspace $workspace) use ($normalizedPath): bool {
                $workspacePath = rtrim($workspace->path, '/');

                return $normalizedPath === $workspacePath || str_starts_with($normalizedPath, "{$workspacePath}/");
            })?->app;
    }

    private function stringValue(Request $request, string $key): ?string
    {
        $value = $request->query($key, $request->input($key));

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function validationFailed(string $field, string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => array_merge(['field' => $field], $meta),
            ],
        ], 400);
    }

    private function stepNotFound(int $id, string $app, WorkspaceLifecyclePhase $phase): JsonResponse
    {
        $label = ucfirst($phase->value);

        return response()->json([
            'error' => [
                'code' => 'workspace.step_not_found',
                'message' => "{$label} step '{$id}' not found for app '{$app}' in phase '{$phase->value}'.",
                'meta' => [
                    'step_id' => $id,
                    'app' => $app,
                    'phase' => $phase->value,
                ],
            ],
        ], 404);
    }

    private function appNotFound(string $app): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'workspace.app_not_found',
                'message' => "App '{$app}' not found.",
                'meta' => [
                    'app' => $app,
                ],
            ],
        ], 404);
    }

    private function workspaceUnsupportedForProduction(WorkspaceUnsupportedForProduction $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $exception->errorCode(),
                'message' => $exception->getMessage(),
                'meta' => $exception->meta,
            ],
        ], 422);
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

    private function forbidden(Node $servingNode, AuthorizationResult $result, string $permission): JsonResponse
    {
        return $this->authorizationFailed(
            "This node is not authorized for '{$permission}' on '{$servingNode->name}'.",
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $servingNode->name,
            ],
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
        return 'api:DELETE /workspaces/steps/{phase}/{step}';
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
