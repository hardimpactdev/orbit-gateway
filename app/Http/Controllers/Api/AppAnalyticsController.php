<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\AppAnalyticsBinding;
use App\Services\Analytics\AnalyticsRouteRegistrar;
use App\Services\Analytics\AppAnalyticsBindingService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

final class AppAnalyticsController implements Loggable
{
    private ?App $activitySubject = null;

    private ?string $activityTargetName = null;

    private ActivityLogType $activityEffect = ActivityLogType::Read;

    private string $activityType = 'api:GET /apps/{app}/analytics';

    private string $activityAction = 'show';

    /**
     * @var list<string>
     */
    private array $activityPublicHosts = [];

    #[RequiresPermission('app:write', servingNode: ServingNode::AppOwning)]
    public function enable(Request $request, string $app, AppAnalyticsBindingService $service): JsonResponse
    {
        $this->activityTargetName = $app;
        $this->activityEffect = ActivityLogType::Write;
        $this->activityType = 'api:POST /apps/{app}/analytics/enable';
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

        $publicHosts = $request->input('public_hosts', []);

        if (! is_array($publicHosts)) {
            return $this->error(
                code: 'validation_failed',
                message: 'Public hosts must be an array.',
                meta: ['field' => 'public_hosts'],
                status: 422,
            );
        }

        try {
            $binding = $service->enable($targetApp, $publicHosts);
        } catch (InvalidArgumentException $exception) {
            return $this->error(
                code: 'validation_failed',
                message: $exception->getMessage(),
                meta: ['field' => 'public_hosts'],
                status: 422,
            );
        } catch (DomainException|RuntimeException $exception) {
            return $this->error(
                code: 'analytics.prerequisite_failed',
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

    #[RequiresPermission('app:write', servingNode: ServingNode::AppOwning)]
    public function disable(string $app, AppAnalyticsBindingService $service): JsonResponse
    {
        $this->activityTargetName = $app;
        $this->activityEffect = ActivityLogType::Write;
        $this->activityType = 'api:POST /apps/{app}/analytics/disable';
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
                code: 'analytics.binding_missing',
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

    #[RequiresPermission('app:read', servingNode: ServingNode::AppOwning)]
    public function show(string $app, AppAnalyticsBindingService $service): JsonResponse
    {
        $this->activityTargetName = $app;
        $this->activityEffect = ActivityLogType::Read;
        $this->activityType = 'api:GET /apps/{app}/analytics';
        $this->activityAction = 'show';

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
            $binding = $service->show($targetApp);
        } catch (RuntimeException $exception) {
            return $this->error(
                code: 'analytics.binding_missing',
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
     *     enabled: bool,
     *     internal_host: string,
     *     dashboard_url: string,
     *     public_hosts: list<string>,
     *     tracking_paths: list<string>,
     * }
     */
    private function bindingPayload(AppAnalyticsBinding $binding): array
    {
        $binding->loadMissing('app');

        return [
            'app' => $binding->app->name,
            'enabled' => $binding->enabled,
            'internal_host' => AnalyticsRouteRegistrar::ServiceDomain,
            'dashboard_url' => 'https://'.AnalyticsRouteRegistrar::ServiceDomain,
            'public_hosts' => $this->stringList($binding->public_hosts),
            'tracking_paths' => AnalyticsRouteRegistrar::TrackingPaths,
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

        return "Analytics {$this->activityAction} for {$target}";
    }
}
