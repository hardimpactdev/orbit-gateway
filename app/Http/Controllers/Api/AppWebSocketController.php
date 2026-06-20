<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\EnableAppWebSocketApiRequest;
use App\Models\App;
use App\Models\AppWebSocketBinding;
use App\Services\WebSockets\WebSocketBindingService;
use App\Services\WebSockets\WebSocketRouteRegistrar;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;

final class AppWebSocketController implements Loggable
{
    private ?App $activitySubject = null;

    private ?string $activityTargetName = null;

    private ActivityLogType $activityEffect = ActivityLogType::Read;

    private string $activityType = 'api:GET /apps/{app}/websocket/credentials';

    private string $activityAction = 'credentials';

    /**
     * @var list<string>
     */
    private array $activityPublicHosts = [];

    #[RequiresPermission('app:write', servingNode: ServingNode::AppOwning)]
    public function enable(EnableAppWebSocketApiRequest $request, string $app, WebSocketBindingService $service): JsonResponse
    {
        $this->activityTargetName = $app;
        $this->activityEffect = ActivityLogType::Write;
        $this->activityType = 'api:POST /apps/{app}/websocket/enable';
        $this->activityAction = 'enable';

        $targetApp = $this->resolveApp($app);

        if (! $targetApp instanceof App) {
            return $this->error(
                code: 'app.not_found',
                message: "App '{$app}' not found.",
                meta: ['app' => $app],
                status: 404,
            );
        }

        try {
            $binding = $service->enable($targetApp, $request->publicHosts());
        } catch (InvalidArgumentException $exception) {
            return $this->error(
                code: 'validation_failed',
                message: $exception->getMessage(),
                meta: ['field' => 'public_hosts'],
                status: 422,
            );
        } catch (DomainException|RuntimeException $exception) {
            return $this->error(
                code: 'websocket.prerequisite_failed',
                message: $exception->getMessage(),
                meta: ['app' => $targetApp->name],
                status: 422,
            );
        }

        $this->activitySubject = $targetApp->refresh();
        $this->activityPublicHosts = $this->stringList($binding->public_hosts);

        return response()->json([
            'success' => [
                'data' => [
                    'binding' => $this->bindingPayload($binding),
                ],
            ],
        ]);
    }

    #[RequiresPermission('app:credentials', servingNode: ServingNode::AppOwning)]
    public function credentials(string $app, WebSocketBindingService $service): JsonResponse
    {
        $this->activityTargetName = $app;
        $this->activityEffect = ActivityLogType::Read;
        $this->activityType = 'api:GET /apps/{app}/websocket/credentials';
        $this->activityAction = 'credentials';

        $targetApp = $this->resolveApp($app);

        if (! $targetApp instanceof App) {
            return $this->error(
                code: 'app.not_found',
                message: "App '{$app}' not found.",
                meta: ['app' => $app],
                status: 404,
            );
        }

        try {
            $credentials = $service->credentials($targetApp);
        } catch (RuntimeException $exception) {
            return $this->error(
                code: 'websocket.binding_missing',
                message: $exception->getMessage(),
                meta: ['app' => $targetApp->name],
                status: 422,
            );
        }

        $this->activitySubject = $targetApp->refresh();

        return response()->json([
            'success' => [
                'data' => [
                    'credentials' => $credentials->toArray(),
                ],
            ],
        ]);
    }

    #[RequiresPermission('app:write', servingNode: ServingNode::AppOwning)]
    public function disable(string $app, WebSocketBindingService $service): JsonResponse
    {
        $this->activityTargetName = $app;
        $this->activityEffect = ActivityLogType::Write;
        $this->activityType = 'api:POST /apps/{app}/websocket/disable';
        $this->activityAction = 'disable';

        $targetApp = $this->resolveApp($app);

        if (! $targetApp instanceof App) {
            return $this->error(
                code: 'app.not_found',
                message: "App '{$app}' not found.",
                meta: ['app' => $app],
                status: 404,
            );
        }

        try {
            $binding = $service->disable($targetApp);
        } catch (RuntimeException $exception) {
            return $this->error(
                code: 'websocket.binding_missing',
                message: $exception->getMessage(),
                meta: ['app' => $targetApp->name],
                status: 422,
            );
        }

        $this->activitySubject = $targetApp->refresh();
        $this->activityPublicHosts = $this->stringList($binding->public_hosts);

        return response()->json([
            'success' => [
                'data' => [
                    'binding' => $this->bindingPayload($binding),
                ],
            ],
        ]);
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with('node')
            ->get()
            ->filter(fn (App $app): bool => $app->name === $selector
                || $app->domain === $selector
                || $app->url() === "https://{$selector}"
                || $app->url() === $selector)
            ->values()
            ->first();
    }

    /**
     * @return array{
     *     app: string,
     *     internal_host: string,
     *     public_hosts: list<string>,
     *     allowed_origins: list<string>,
     * }
     */
    private function bindingPayload(AppWebSocketBinding $binding): array
    {
        $binding->loadMissing('app');

        return [
            'app' => $binding->app->name,
            'internal_host' => WebSocketRouteRegistrar::ServiceDomain,
            'public_hosts' => $this->stringList($binding->public_hosts),
            'allowed_origins' => $this->stringList($binding->allowed_origins),
        ];
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
                'meta' => $meta,
            ],
        ], $status);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    public function effect(): ActivityLogType
    {
        return $this->activityEffect;
    }

    public function type(): string
    {
        return $this->activityType;
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [
            'action' => $this->activityAction,
            'target_app' => $this->activityTargetName ?? (string) request()->route('app'),
            'public_hosts' => $this->activityPublicHosts,
        ];
    }

    public function description(): ?string
    {
        $target = $this->activityTargetName ?? (string) request()->route('app');

        if ($target === '') {
            return null;
        }

        return match ($this->activityAction) {
            'enable' => "App {$target} websocket enabled",
            'disable' => "App {$target} websocket disabled",
            default => "App {$target} websocket credentials viewed",
        };
    }
}
