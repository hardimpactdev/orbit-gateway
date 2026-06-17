<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use App\Data\Apps\LaravelCloudAppInstanceDriverConfigData;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\ActivityLogType;
use App\Enums\Apps\AppInstanceDriver;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Services\Apps\AppInstancePayloads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ValueError;

final class AppInstanceController implements Loggable
{
    private ?App $activitySubject = null;

    private string $currentAction = 'list';

    public function __construct(
        private readonly AppInstancePayloads $payloads,
    ) {}

    #[RequiresPermission('app:read', servingNode: ServingNode::AppOwning)]
    public function index(string $app): JsonResponse
    {
        $this->currentAction = 'list';
        $targetApp = $this->resolveApp($app);
        $this->activitySubject = $targetApp;

        if (! $targetApp instanceof App) {
            return $this->appNotFound($app);
        }

        $instances = $targetApp->instances()->with(['app.node', 'app.runtimeMounts'])->get();

        return $this->success([
            'app' => $targetApp->name,
            'instances' => $instances
                ->map(fn (AppInstance $instance): array => $this->payloads->instance($instance))
                ->values()
                ->all(),
        ], ['count' => $instances->count()]);
    }

    #[RequiresPermission('app:read', servingNode: ServingNode::AppOwning)]
    public function show(string $app, string $instance): JsonResponse
    {
        $this->currentAction = 'show';
        $resolved = $this->resolveAppInstance($app, $instance);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$targetApp, $targetInstance] = $resolved;
        $this->activitySubject = $targetApp;

        return $this->success($this->payloads->withCompatibility($targetInstance));
    }

    #[RequiresPermission('app:write', servingNode: ServingNode::AppOwning)]
    public function store(string $app, Request $request): JsonResponse
    {
        $this->currentAction = 'add';
        $targetApp = $this->resolveApp($app);
        $this->activitySubject = $targetApp;

        if (! $targetApp instanceof App) {
            return $this->appNotFound($app);
        }

        $name = $this->stringInput($request, 'name') ?? $this->stringInput($request, 'instance');

        if ($name === null) {
            return $this->validationFailed('name', 'Instance name is required.');
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) || mb_strlen($name) > 40) {
            return $this->validationFailed('name', 'Instance name must be a slug of 40 characters or fewer.');
        }

        if ($targetApp->instances()->where('name', $name)->exists()) {
            return $this->validationFailed('name', "Instance '{$name}' already exists for app '{$targetApp->name}'.", [
                'app' => $targetApp->name,
                'instance' => $name,
            ], 422);
        }

        $driver = $this->driver($request);

        if ($driver instanceof JsonResponse) {
            return $driver;
        }

        $driverConfig = match ($driver) {
            AppInstanceDriver::Orbit => $this->orbitDriverConfig($targetApp, $request),
            AppInstanceDriver::LaravelCloud => $this->laravelCloudDriverConfig($request),
        };

        if ($driverConfig instanceof JsonResponse) {
            return $driverConfig;
        }

        $instance = $targetApp->instances()->create([
            'name' => $name,
            'driver' => $driver,
            'driver_config' => $driverConfig,
            'runtime_requirements' => new AppInstanceRuntimeRequirementsData(
                php_extensions: $this->phpExtensions($request),
            ),
        ]);

        return $this->success($this->payloads->withCompatibility($instance));
    }

    #[RequiresPermission('app:write', servingNode: ServingNode::AppOwning)]
    public function destroy(string $app, string $instance, Request $request): JsonResponse
    {
        $this->currentAction = 'remove';
        $resolved = $this->resolveAppInstance($app, $instance);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$targetApp, $targetInstance] = $resolved;
        $this->activitySubject = $targetApp;

        if (! $request->boolean('destructive_consent') && ! $request->boolean('force')) {
            return $this->validationFailed('force', 'Removing an app instance requires destructive consent.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ], 422);
        }

        $targetInstance->delete();

        return $this->success([
            'result' => [
                'action' => 'removed',
                'app' => $targetApp->name,
                'instance' => $instance,
            ],
        ]);
    }

    private function driver(Request $request): AppInstanceDriver|JsonResponse
    {
        $value = $this->stringInput($request, 'driver') ?? AppInstanceDriver::Orbit->value;

        try {
            return AppInstanceDriver::from($value);
        } catch (ValueError) {
            return $this->validationFailed('driver', 'Driver must be one of: orbit, laravel-cloud.', [
                'field' => 'driver',
                'value' => $value,
                'allowed' => array_map(static fn (AppInstanceDriver $driver): string => $driver->value, AppInstanceDriver::cases()),
            ], 422);
        }
    }

    private function orbitDriverConfig(App $app, Request $request): OrbitAppInstanceDriverConfigData|JsonResponse
    {
        $nodeSelector = $this->stringInput($request, 'node');
        $app->loadMissing('node');
        $node = $nodeSelector === null
            ? $app->node
            : Node::query()->where('name', $nodeSelector)->first();

        if (! $node instanceof Node) {
            return $this->validationFailed('node', 'Orbit app instances require a valid --node value.', [
                'field' => 'node',
                'value' => $nodeSelector,
            ], 422);
        }

        return new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $this->stringInput($request, 'path') ?? $app->path,
            document_root: $this->stringInput($request, 'root') ?? $this->stringInput($request, 'document_root') ?? $app->document_root,
            domain: $this->stringInput($request, 'domain'),
        );
    }

    private function laravelCloudDriverConfig(Request $request): LaravelCloudAppInstanceDriverConfigData|JsonResponse
    {
        $application = $this->stringInput($request, 'cloud_application')
            ?? $this->stringInput($request, 'cloud_app')
            ?? $this->stringInput($request, 'cloud_application_id')
            ?? $this->stringInput($request, 'cloud_application_name');
        $environment = $this->stringInput($request, 'cloud_environment')
            ?? $this->stringInput($request, 'cloud_environment_id')
            ?? $this->stringInput($request, 'cloud_environment_name');

        if ($application === null) {
            return $this->validationFailed('cloud_application', 'Laravel Cloud app selector is required for laravel-cloud instances.');
        }

        if ($environment === null) {
            return $this->validationFailed('cloud_environment', 'Laravel Cloud environment selector is required for laravel-cloud instances.');
        }

        return new LaravelCloudAppInstanceDriverConfigData(
            organization_id: $this->stringInput($request, 'cloud_organization_id'),
            organization_name: $this->stringInput($request, 'cloud_organization_name'),
            application_id: $this->stringInput($request, 'cloud_application_id'),
            application_name: $this->stringInput($request, 'cloud_application_name'),
            environment_id: $this->stringInput($request, 'cloud_environment_id'),
            environment_name: $this->stringInput($request, 'cloud_environment_name'),
            application: $application,
            environment: $environment,
            domain: $this->stringInput($request, 'domain'),
        );
    }

    /**
     * @return list<string>
     */
    private function phpExtensions(Request $request): array
    {
        $extensions = $request->input('php_extensions', []);

        if (is_string($extensions)) {
            $extensions = [$extensions];
        }

        if (! is_array($extensions)) {
            return [];
        }

        $normalized = array_values(array_filter(
            array_map(static fn (mixed $extension): ?string => is_string($extension) ? strtolower(trim($extension)) : null, $extensions),
            static fn (?string $extension): bool => $extension !== null && $extension !== '',
        ));

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @return array{0: App, 1: AppInstance}|JsonResponse
     */
    private function resolveAppInstance(string $app, string $instance): array|JsonResponse
    {
        $targetApp = $this->resolveApp($app);

        if (! $targetApp instanceof App) {
            return $this->appNotFound($app);
        }

        $targetInstance = $targetApp->instances()
            ->with(['app.node', 'app.runtimeMounts'])
            ->where('name', $instance)
            ->first();

        if (! $targetInstance instanceof AppInstance) {
            return response()->json([
                'error' => [
                    'code' => 'app_instance.not_found',
                    'message' => "Instance '{$instance}' was not found for app '{$targetApp->name}'.",
                    'meta' => [
                        'app' => $targetApp->name,
                        'instance' => $instance,
                    ],
                ],
            ], 404);
        }

        return [$targetApp, $targetInstance];
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with(['node', 'instances'])
            ->where('name', $selector)
            ->orWhere('domain', $selector)
            ->first();
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

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function success(array $data, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => [
                'data' => $data,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ]);
    }

    private function appNotFound(string $app): JsonResponse
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
    private function validationFailed(string $field, string $message, array $meta = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => $meta === [] ? ['field' => $field] : ['field' => $field, ...$meta],
            ],
        ], $status);
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function effect(): ActivityLogType
    {
        return in_array($this->currentAction, ['list', 'show'], true) ? ActivityLogType::Read : ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return match ($this->currentAction) {
            'add' => 'api:POST /apps/{app}/instances',
            'remove' => 'api:DELETE /apps/{app}/instances/{instance}',
            'show' => 'api:GET /apps/{app}/instances/{instance}',
            default => 'api:GET /apps/{app}/instances',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
