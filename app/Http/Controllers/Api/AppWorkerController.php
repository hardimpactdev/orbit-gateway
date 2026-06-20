<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Services\Apps\AppWorkerService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

final class AppWorkerController implements Loggable
{
    private ?App $activitySubject = null;

    private string $currentAction = 'show';

    #[RequiresPermission('app:read', servingNode: ServingNode::AppOwning)]
    public function show(string $app, AppWorkerService $service): JsonResponse
    {
        return $this->dispatch('show', $app, $service);
    }

    #[RequiresPermission('app:worker', servingNode: ServingNode::AppOwning)]
    public function enable(string $app, AppWorkerService $service): JsonResponse
    {
        return $this->dispatch('enable', $app, $service);
    }

    #[RequiresPermission('app:worker', servingNode: ServingNode::AppOwning)]
    public function disable(string $app, AppWorkerService $service): JsonResponse
    {
        return $this->dispatch('disable', $app, $service);
    }

    private function dispatch(string $action, string $app, AppWorkerService $service): JsonResponse
    {
        $this->currentAction = $action;
        $targetApp = $this->resolveApp($app);
        $this->activitySubject = $targetApp;

        if (! $targetApp instanceof App) {
            return response()->json([
                'error' => [
                    'code' => 'app.not_found',
                    'message' => "App '{$app}' not found.",
                    'meta' => ['app' => $app],
                ],
            ], 404);
        }

        if ($action === 'show') {
            return $this->success($this->workerPayload($targetApp));
        }

        if ($action === 'enable') {
            $result = $service->enable($targetApp);

            if (! $result['ready']) {
                $readiness = $result['readiness'];

                return response()->json([
                    'error' => [
                        'code' => $readiness->code ?? 'app.worker_readiness_failed',
                        'message' => $readiness->message ?? "App '{$targetApp->name}' is not ready for worker mode.",
                        'meta' => array_merge([
                            'app' => $targetApp->name,
                            'missing' => $readiness->missing,
                        ], $readiness->meta),
                    ],
                ], 422);
            }

            return $this->success(array_merge(
                $this->workerPayload($result['app']),
                ['changed' => $result['changed']],
            ));
        }

        $result = $service->disable($targetApp);

        return $this->success(array_merge(
            $this->workerPayload($result['app']),
            ['changed' => $result['changed']],
        ));
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

    private function resolveApp(string $selector): ?App
    {
        $baseQuery = App::query()->with('node');

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
     * @return array{app: string, worker_enabled: bool, worker_config: array<string, mixed>|null}
     */
    private function workerPayload(App $app): array
    {
        return [
            'app' => $app->name,
            'worker_enabled' => $app->worker_enabled,
            'worker_config' => is_array($app->worker_config) ? $app->worker_config : null,
        ];
    }

    public function effect(): ActivityLogType
    {
        return $this->currentAction === 'show' ? ActivityLogType::Read : ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return match ($this->currentAction) {
            'enable' => 'api:POST /apps/{app}/worker/enable',
            'disable' => 'api:POST /apps/{app}/worker/disable',
            default => 'api:GET /apps/{app}/worker',
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
        return [
            'action' => $this->currentAction,
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
