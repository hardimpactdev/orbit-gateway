<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\AppRuntimeMount;
use App\Services\Apps\AppRuntimeMountService;
use App\Services\Apps\AppRuntimeMountValidationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AppRuntimeMountController implements Loggable
{
    private ?App $activitySubject = null;

    private string $currentAction = 'list';

    private ?string $currentTarget = null;

    #[RequiresPermission('app:read', servingNode: ServingNode::AppOwning)]
    public function index(string $app, AppRuntimeMountService $mounts): JsonResponse
    {
        $this->currentAction = 'list';

        $targetApp = $this->resolveApp($app);
        $this->activitySubject = $targetApp;

        if (! $targetApp instanceof App) {
            return $this->notFound($app);
        }

        return $this->success($this->mountsPayload($targetApp, $mounts->list($targetApp), $mounts));
    }

    #[RequiresPermission('app:mount', servingNode: ServingNode::AppOwning)]
    public function store(string $app, Request $request, AppRuntimeMountService $mounts): JsonResponse
    {
        $this->currentAction = 'add';

        $targetApp = $this->resolveApp($app);
        $this->activitySubject = $targetApp;

        if (! $targetApp instanceof App) {
            return $this->notFound($app);
        }

        $source = $this->stringInput($request, 'source');
        $target = $this->stringInput($request, 'target');
        $this->currentTarget = $target;

        if ($source === null) {
            return $this->validationFailed('Source path is required.', ['field' => 'source']);
        }

        if ($target === null) {
            return $this->validationFailed('Target path is required.', ['field' => 'target']);
        }

        try {
            $result = $mounts->add(
                app: $targetApp,
                source: $source,
                target: $target,
                readOnly: $this->readOnly($request),
            );
        } catch (AppRuntimeMountValidationException $exception) {
            return $this->validationFailed($exception->getMessage(), $exception->meta);
        }

        return $this->success([
            ...$this->mountsPayload($targetApp, $result['mounts'], $mounts),
            'mount' => $mounts->mountPayload($result['mount']),
            'action' => $result['action'],
        ]);
    }

    #[RequiresPermission('app:mount', servingNode: ServingNode::AppOwning)]
    public function destroy(string $app, Request $request, AppRuntimeMountService $mounts): JsonResponse
    {
        $this->currentAction = 'remove';

        $targetApp = $this->resolveApp($app);
        $this->activitySubject = $targetApp;

        if (! $targetApp instanceof App) {
            return $this->notFound($app);
        }

        $target = $this->stringInput($request, 'target');
        $this->currentTarget = $target;

        if ($target === null) {
            return $this->validationFailed('Target path is required.', ['field' => 'target']);
        }

        try {
            $result = $mounts->remove($targetApp, $target);
        } catch (AppRuntimeMountValidationException $exception) {
            return $this->validationFailed($exception->getMessage(), $exception->meta);
        }

        $payload = [
            ...$this->mountsPayload($targetApp, $result['mounts'], $mounts),
            'action' => $result['action'],
        ];

        if ($result['mount'] instanceof AppRuntimeMount) {
            $payload['mount'] = $mounts->mountPayload($result['mount']);
        }

        return $this->success($payload);
    }

    private function readOnly(Request $request): bool
    {
        if ($request->has('read_write')) {
            return ! $request->boolean('read_write');
        }

        return $request->boolean('read_only', true);
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function resolveApp(string $selector): ?App
    {
        $baseQuery = App::query()->with(['node.roleAssignments', 'runtimeMounts']);

        $nameMatch = (clone $baseQuery)
            ->where('name', $selector)
            ->first();

        if ($nameMatch instanceof App) {
            return $nameMatch;
        }

        return $baseQuery
            ->where('domain', $selector)
            ->first();
    }

    /**
     * @param  Collection<int, AppRuntimeMount>  $mounts
     * @return array{
     *     app: array<string, mixed>,
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     inherited_by_workspaces: bool
     * }
     */
    private function mountsPayload(App $app, Collection $mounts, AppRuntimeMountService $service): array
    {
        $app->loadMissing('node');

        return [
            'app' => $this->appPayload($app),
            'mounts' => $mounts
                ->map(fn (AppRuntimeMount $mount): array => $service->mountPayload($mount))
                ->values()
                ->all(),
            'inherited_by_workspaces' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        $app->loadMissing('node');

        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'runtime_kind' => $app->runtime_kind->value,
            'php_version' => $app->php_version,
            'worker_enabled' => (bool) $app->worker_enabled,
            'worker_config' => is_array($app->worker_config) ? $app->worker_config : null,
            'adopted' => (bool) $app->adopted,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function success(array $data): JsonResponse
    {
        return response()->json([
            'success' => [
                'data' => $data,
            ],
        ]);
    }

    private function notFound(string $app): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'app.not_found',
                'message' => "App '{$app}' not found.",
                'meta' => ['app' => $app],
            ],
        ], 404);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function validationFailed(string $message, array $meta): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => $meta,
            ],
        ], 422);
    }

    public function effect(): ActivityLogType
    {
        return $this->currentAction === 'list' ? ActivityLogType::Read : ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return match ($this->currentAction) {
            'add' => 'api:POST /apps/{app}/mounts',
            'remove' => 'api:DELETE /apps/{app}/mounts',
            default => 'api:GET /apps/{app}/mounts',
        };
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
        return array_filter([
            'action' => $this->currentAction,
            'target' => $this->currentTarget,
        ], fn (mixed $value): bool => $value !== null);
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
