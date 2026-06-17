<?php

declare(strict_types=1);

namespace App\Services\AgentIde;

use App\Contracts\AgentIdeMessageAdapter;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Workspace;
use App\Services\Apps\AppAgentIdeDefaults;

final readonly class AgentIdeMessageDelivery
{
    public function __construct(
        private AppAgentIdeDefaults $appAgentIdeDefaults,
        private AgentIdeAdapterRegistry $registry,
    ) {}

    /**
     * @return array{agent_ide: array<string, mixed>}
     */
    public function deliverToApp(string $selector, string $message): array
    {
        $app = $this->resolveApp($selector);

        if (! $app instanceof App) {
            throw new GatewayApiException(
                message: "App '{$selector}' not found or not visible.",
                errorCode: 'target_not_found',
                errorMeta: ['app' => $selector],
            );
        }

        $adapter = $this->appAgentIdeDefaults->payloadFor($app);
        $adapterName = $adapter['effective_adapter'];

        if ($adapterName === null) {
            throw new GatewayApiException(
                message: "No Agent IDE adapter is configured for {$app->name}.",
                errorCode: 'no_effective_adapter',
                errorMeta: ['app' => $app->name, 'workspace' => null],
            );
        }

        if (! $this->registry->isRegisteredAdapter($adapterName)) {
            throw new GatewayApiException(
                message: "Agent IDE adapter {$adapterName} is not registered.",
                errorCode: 'no_effective_adapter',
                errorMeta: ['app' => $app->name, 'workspace' => null, 'adapter' => $adapterName],
            );
        }

        $target = [
            'app' => $app->name,
            'workspace' => null,
            'node' => (string) $app->node?->name,
        ];
        $messageAdapter = $this->messageAdapter();
        $session = $messageAdapter->activeSession($target, $adapterName);

        if ($session === null) {
            throw new GatewayApiException(
                message: "No active Agent IDE session found for {$app->name}.",
                errorCode: 'no_active_session',
                errorMeta: ['app' => $app->name, 'workspace' => null, 'adapter' => $adapterName],
            );
        }

        $messageAdapter->deliver($target, $adapterName, $session, $message);

        return [
            'agent_ide' => [
                'adapter' => $adapterName,
                'source' => $adapter['source'],
                'target' => $target,
                'session' => $session,
                'delivery' => [
                    'status' => 'sent',
                    'message_bytes' => strlen($message),
                    'input' => 'argument',
                ],
            ],
        ];
    }

    /**
     * @return array{agent_ide: array<string, mixed>}
     */
    public function deliverToWorkspace(string $selector, string $message): array
    {
        $workspace = $this->resolveWorkspace($selector);

        if (! $workspace instanceof Workspace) {
            throw new GatewayApiException(
                message: "Workspace '{$selector}' not found or not visible.",
                errorCode: 'target_not_found',
                errorMeta: ['workspace' => $selector],
            );
        }

        $workspace->loadMissing('app.node');
        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new GatewayApiException(
                message: "Workspace '{$selector}' not found or not visible.",
                errorCode: 'target_not_found',
                errorMeta: ['workspace' => $selector],
            );
        }

        $adapter = $this->workspaceAdapterPayload($workspace, $app);
        $adapterName = $adapter['effective_adapter'];

        if ($adapterName === null) {
            throw new GatewayApiException(
                message: "No Agent IDE adapter is configured for workspace {$workspace->name}.",
                errorCode: 'no_effective_adapter',
                errorMeta: ['app' => $app->name, 'workspace' => $workspace->name],
            );
        }

        if (! $this->registry->isRegisteredAdapter($adapterName)) {
            throw new GatewayApiException(
                message: "Agent IDE adapter {$adapterName} is not registered.",
                errorCode: 'no_effective_adapter',
                errorMeta: ['app' => $app->name, 'workspace' => $workspace->name, 'adapter' => $adapterName],
            );
        }

        $target = [
            'app' => $app->name,
            'workspace' => $workspace->name,
            'node' => (string) $app->node?->name,
        ];
        $messageAdapter = $this->messageAdapter();
        $session = $messageAdapter->activeSession($target, $adapterName);

        if ($session === null) {
            throw new GatewayApiException(
                message: "No active Agent IDE session found for workspace {$workspace->name}.",
                errorCode: 'no_active_session',
                errorMeta: ['app' => $app->name, 'workspace' => $workspace->name, 'adapter' => $adapterName],
            );
        }

        $messageAdapter->deliver($target, $adapterName, $session, $message);

        return [
            'agent_ide' => [
                'adapter' => $adapterName,
                'source' => $adapter['source'],
                'target' => $target,
                'session' => $session,
                'delivery' => [
                    'status' => 'sent',
                    'message_bytes' => strlen($message),
                    'input' => 'argument',
                ],
            ],
        ];
    }

    /**
     * @return array{agent_ide: array<string, mixed>}
     */
    public function deliverToPath(string $path, string $message): array
    {
        $workspace = $this->resolveWorkspaceFromPath($path);

        if ($workspace instanceof Workspace) {
            return $this->deliverToWorkspace($workspace->name, $message);
        }

        $app = $this->resolveAppFromPath($path);

        if ($app instanceof App) {
            return $this->deliverToApp($app->name, $message);
        }

        throw new GatewayApiException(
            message: 'Run this command from an app/workspace directory or pass --app/--workspace.',
            errorCode: 'validation_failed',
            errorMeta: ['field' => 'target'],
        );
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
     * @return array{source: string, effective_adapter: string|null}
     */
    private function workspaceAdapterPayload(Workspace $workspace, App $app): array
    {
        if (is_string($workspace->agent_ide) && $workspace->agent_ide !== '') {
            return [
                'source' => 'workspace',
                'effective_adapter' => $workspace->agent_ide === 'none' ? null : $workspace->agent_ide,
            ];
        }

        $adapter = $this->appAgentIdeDefaults->payloadFor($app);

        return [
            'source' => $adapter['source'],
            'effective_adapter' => $adapter['effective_adapter'],
        ];
    }

    private function messageAdapter(): AgentIdeMessageAdapter
    {
        return app()->bound(AgentIdeMessageAdapter::class)
            ? app(AgentIdeMessageAdapter::class)
            : new NullAgentIdeMessageAdapter;
    }
}
