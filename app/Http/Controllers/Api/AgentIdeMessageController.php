<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Http\Requests\Api\SendAgentIdeMessageApiRequest;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\AgentIde\AgentIdeMessageDelivery;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

final class AgentIdeMessageController implements Loggable
{
    private ?Model $activitySubject = null;

    /**
     * @var array<string, mixed>
     */
    private array $activityProperties = [];

    public function __construct(
        private readonly AgentIdeMessageDelivery $delivery,
        private readonly NodeAccessAuthorizer $authorizer,
    ) {}

    public function __invoke(SendAgentIdeMessageApiRequest $request): JsonResponse
    {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->error(
                code: 'authorization_failed',
                message: 'Peer identity unknown.',
                meta: [],
                status: 403,
            );
        }

        $workspaceSelector = $request->workspaceSelector();

        if ($workspaceSelector !== null) {
            return $this->sendWorkspaceMessage($request, $caller, $workspaceSelector);
        }

        $pathSelector = $request->pathSelector();

        if ($pathSelector !== null) {
            return $this->sendPathMessage($request, $caller, $pathSelector);
        }

        $app = $this->resolveApp($request->appSelector());

        if (! $app instanceof App) {
            return $this->error(
                code: 'target_not_found',
                message: "App '{$request->appSelector()}' not found or not visible.",
                meta: ['app' => $request->appSelector()],
                status: 404,
            );
        }

        return $this->sendAppMessage($request, $caller, $app);
    }

    private function sendPathMessage(SendAgentIdeMessageApiRequest $request, Node $caller, string $path): JsonResponse
    {
        $workspace = $this->resolveWorkspaceFromPath($path);

        if ($workspace instanceof Workspace) {
            return $this->sendWorkspaceMessage($request, $caller, $workspace->name);
        }

        $app = $this->resolveAppFromPath($path);

        if ($app instanceof App) {
            return $this->sendAppMessage($request, $caller, $app);
        }

        return $this->error(
            code: 'validation_failed',
            message: 'Run this command from an app/workspace directory or pass --app/--workspace.',
            meta: ['field' => 'target'],
            status: 422,
        );
    }

    private function sendAppMessage(SendAgentIdeMessageApiRequest $request, Node $caller, App $app): JsonResponse
    {
        $app->loadMissing('node');

        $authorizationMeta = $this->messageAuthorizationMeta($caller, $app);

        if ($authorizationMeta !== null) {
            return $this->error(
                code: 'authorization_failed',
                message: "This node is not authorized to message app '{$app->name}'.",
                meta: $authorizationMeta,
                status: 403,
            );
        }

        try {
            $data = $this->delivery->deliverToApp($app->name, $request->messageBody());
            $this->rememberDeliveryActivity($app, $data);
        } catch (GatewayApiException $e) {
            $this->rememberFailureActivity($app, $e);

            return $this->error(
                code: $e->errorCode() ?? 'adapter_delivery_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $this->statusFor($e->errorCode()),
                data: $e->errorData(),
            );
        }

        return response()->json([
            'success' => [
                'data' => $data,
            ],
        ]);
    }

    private function sendWorkspaceMessage(SendAgentIdeMessageApiRequest $request, Node $caller, string $workspaceSelector): JsonResponse
    {
        $workspace = $this->resolveWorkspace($workspaceSelector);

        if (! $workspace instanceof Workspace || ! $workspace->app instanceof App) {
            return $this->error(
                code: 'target_not_found',
                message: "Workspace '{$workspaceSelector}' not found or not visible.",
                meta: ['workspace' => $workspaceSelector],
                status: 404,
            );
        }

        $workspace->app->loadMissing('node');

        $authorizationMeta = $this->messageAuthorizationMeta($caller, $workspace->app, $workspace);

        if ($authorizationMeta !== null) {
            return $this->error(
                code: 'authorization_failed',
                message: "This node is not authorized to message workspace '{$workspace->name}'.",
                meta: $authorizationMeta,
                status: 403,
            );
        }

        try {
            $data = $this->delivery->deliverToWorkspace($workspace->name, $request->messageBody());
            $this->rememberDeliveryActivity($workspace, $data);
        } catch (GatewayApiException $e) {
            $this->rememberFailureActivity($workspace, $e);

            return $this->error(
                code: $e->errorCode() ?? 'adapter_delivery_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $this->statusFor($e->errorCode()),
                data: $e->errorData(),
            );
        }

        return response()->json([
            'success' => [
                'data' => $data,
            ],
        ]);
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with('node')
            ->get()
            ->first(fn (App $app): bool => $app->name === $selector
                || $app->domain === $selector
                || $app->url() === "https://{$selector}");
    }

    private function resolveWorkspace(string $selector): ?Workspace
    {
        $matches = Workspace::query()
            ->with('app.node')
            ->where('name', $selector)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveWorkspaceFromPath(string $path): ?Workspace
    {
        $normalizedPath = rtrim(realpath($path) ?: $path, '/');

        return Workspace::query()
            ->with('app.node')
            ->get()
            ->first(function (Workspace $workspace) use ($normalizedPath): bool {
                $workspacePath = rtrim(realpath($workspace->path) ?: $workspace->path, '/');

                return $normalizedPath === $workspacePath || str_starts_with($normalizedPath, "{$workspacePath}/");
            });
    }

    private function resolveAppFromPath(string $path): ?App
    {
        $normalizedPath = rtrim(realpath($path) ?: $path, '/');

        return App::query()
            ->with('node')
            ->get()
            ->first(function (App $app) use ($normalizedPath): bool {
                $appPath = rtrim(realpath($app->path) ?: $app->path, '/');

                return $normalizedPath === $appPath || str_starts_with($normalizedPath, "{$appPath}/");
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function messageAuthorizationMeta(Node $caller, App $app, ?Workspace $workspace = null): ?array
    {
        $node = $app->node;

        if (! $node instanceof Node) {
            return array_filter([
                'app' => $app->name,
                'workspace' => $workspace?->name,
                'reason' => 'serving_node_unresolved',
                'missing_permission' => 'agent-ide:message',
            ], static fn (mixed $value): bool => $value !== null);
        }

        $result = $this->authorizer->authorize($caller, $node, 'agent-ide:message');

        if ($result->allowed) {
            return null;
        }

        return array_filter([
            'app' => $app->name,
            'workspace' => $workspace?->name,
            'reason' => $result->reason,
            'missing_permission' => $result->missingPermission,
            'serving_node' => $node->name,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array{agent_ide: array<string, mixed>}  $data
     */
    private function rememberDeliveryActivity(Model $subject, array $data): void
    {
        $agentIde = $data['agent_ide'];
        $target = $agentIde['target'] ?? [];

        $this->activitySubject = $subject;
        $this->activityProperties = [
            'target_app' => is_array($target) ? $target['app'] ?? null : null,
            'target_workspace' => is_array($target) ? $target['workspace'] ?? null : null,
            'adapter' => $agentIde['adapter'] ?? null,
            'source' => $agentIde['source'] ?? null,
            'delivery_status' => $agentIde['delivery']['status'] ?? 'sent',
        ];
    }

    private function rememberFailureActivity(Model $subject, GatewayApiException $exception): void
    {
        $meta = $exception->errorMeta();

        $this->activitySubject = $subject;
        $this->activityProperties = [
            'target_app' => $meta['app'] ?? null,
            'target_workspace' => $meta['workspace'] ?? null,
            'adapter' => $meta['adapter'] ?? null,
            'delivery_status' => 'failed',
            'failure_code' => $exception->errorCode(),
        ];
    }

    private function statusFor(?string $code): int
    {
        return match ($code) {
            'target_not_found' => 404,
            'no_effective_adapter', 'no_active_session' => 422,
            default => 500,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status, array $data = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'meta' => $meta,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        return response()->json([
            'error' => $error,
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /agent-ide/message';
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
        return $this->activityProperties;
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
        $targetApp = $this->activityProperties['target_app'] ?? null;
        $targetWorkspace = $this->activityProperties['target_workspace'] ?? null;
        $adapter = $this->activityProperties['adapter'] ?? null;

        if (! is_string($targetApp) || $targetApp === '' || ! is_string($adapter) || $adapter === '') {
            return null;
        }

        $target = is_string($targetWorkspace) && $targetWorkspace !== ''
            ? "{$targetApp}/{$targetWorkspace}"
            : $targetApp;

        if (($this->activityProperties['delivery_status'] ?? null) === 'failed') {
            return "Agent IDE message failed for {$target} through {$adapter}";
        }

        return "Agent IDE message sent to {$target} through {$adapter}";
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
