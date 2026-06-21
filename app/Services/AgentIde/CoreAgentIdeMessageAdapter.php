<?php

declare(strict_types=1);

namespace App\Services\AgentIde;

use App\Contracts\AgentIdeMessageAdapter;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\NodeTool;
use App\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class CoreAgentIdeMessageAdapter implements AgentIdeMessageAdapter
{
    public function activeSession(array $target, string $adapter): ?array
    {
        if ($adapter !== 'opencode') {
            return null;
        }

        $context = $this->resolveOpenCodeContext($target);

        if ($context === null) {
            return null;
        }

        return [
            'id' => $context['session_id'],
            'status' => 'active',
        ];
    }

    public function deliver(array $target, string $adapter, array $session, string $message): array
    {
        if ($adapter !== 'opencode') {
            throw new GatewayApiException(
                message: "Agent IDE adapter {$adapter} does not have a ported message transport.",
                errorCode: 'adapter_delivery_failed',
                errorMeta: ['adapter' => $adapter],
            );
        }

        $context = $this->resolveOpenCodeContext($target, (string) ($session['id'] ?? ''));

        if ($context === null) {
            throw new GatewayApiException(
                message: 'Agent IDE adapter opencode could not resolve an active session.',
                errorCode: 'no_active_session',
                errorMeta: [
                    'app' => $target['app'],
                    'workspace' => $target['workspace'],
                    'adapter' => $adapter,
                ],
            );
        }

        $config = $this->openCodeServerConfig($context['app']);

        try {
            $response = $this->openCodeHttp($config)
                ->post($this->openCodeUrl($config['url'], "/session/{$context['session_id']}/prompt_async"), [
                    'providerID' => null,
                    'modelID' => null,
                    'text' => $message,
                    'directory' => $context['directory'],
                ]);
        } catch (ConnectionException $exception) {
            throw new GatewayApiException(
                message: 'Agent IDE adapter opencode could not reach the OpenCode server.',
                errorCode: 'adapter_delivery_failed',
                errorMeta: $this->failureMeta($target, $adapter, [
                    'transport' => 'http',
                    'reason' => 'connection_failed',
                ]),
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            throw new GatewayApiException(
                message: 'Agent IDE adapter opencode could not deliver the message.',
                errorCode: 'adapter_delivery_failed',
                errorMeta: $this->failureMeta($target, $adapter, [
                    'transport' => 'http',
                    'status' => $response->status(),
                ]),
            );
        }

        return [
            'status' => 'sent',
            'session' => [
                'id' => $context['session_id'],
                'status' => 'active',
            ],
        ];
    }

    /**
     * @param  array{app: string, workspace: string|null, node: string}  $target
     * @return array{app: App, workspace: Workspace, session_id: string, directory: string}|null
     */
    private function resolveOpenCodeContext(array $target, ?string $sessionId = null): ?array
    {
        $app = App::query()
            ->with('node')
            ->where('name', $target['app'])
            ->first();

        if (! $app instanceof App) {
            return null;
        }

        $workspaceQuery = Workspace::query()
            ->where('app_id', $app->id)
            ->whereNotNull('agent_ide_workspace_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if (is_string($target['workspace']) && $target['workspace'] !== '') {
            $workspaceQuery->where('name', $target['workspace']);
        }

        if ($sessionId !== null && $sessionId !== '') {
            $workspaceQuery->where('agent_ide_workspace_id', $sessionId);
        }

        $workspace = $workspaceQuery->first();

        if (! $workspace instanceof Workspace || ! is_string($workspace->agent_ide_workspace_id) || $workspace->agent_ide_workspace_id === '') {
            return null;
        }

        return [
            'app' => $app,
            'workspace' => $workspace,
            'session_id' => $workspace->agent_ide_workspace_id,
            'directory' => $workspace->path,
        ];
    }

    /**
     * @return array{url: string, username: string|null, password: string|null}
     */
    private function openCodeServerConfig(App $app): array
    {
        $app->loadMissing('node');

        $tool = $app->node === null ? null : NodeTool::query()
            ->where('node_id', $app->node->id)
            ->where('name', 'opencode-server')
            ->first();

        $credentials = is_array($tool?->credentials) ? $tool->credentials : [];
        $fields = is_array($credentials['fields'] ?? null) ? $credentials['fields'] : [];
        $endpoint = $this->endpointFromTool($tool);

        return [
            'url' => $this->normalizeBaseUrl(
                $this->stringValue($fields['url'] ?? null)
                    ?? $endpoint
                    ?? 'http://127.0.0.1:4096',
            ),
            'username' => $this->stringValue($fields['username'] ?? null),
            'password' => $this->stringValue($fields['password'] ?? null),
        ];
    }

    private function endpointFromTool(?NodeTool $tool): ?string
    {
        $config = is_array($tool?->config) ? $tool->config : [];
        $endpoints = is_array($config['endpoints'] ?? null) ? $config['endpoints'] : [];

        foreach ($endpoints as $endpoint) {
            if (! is_array($endpoint)) {
                continue;
            }

            $url = $this->stringValue($endpoint['url'] ?? null);

            if ($url !== null) {
                return $url;
            }

            $host = $this->stringValue($endpoint['host'] ?? null);
            $port = $endpoint['port'] ?? null;

            if ($host !== null && (is_int($port) || is_string($port))) {
                $scheme = $this->stringValue($endpoint['scheme'] ?? null) ?? 'http';

                return "{$scheme}://{$host}:{$port}";
            }
        }

        return null;
    }

    /**
     * @param  array{url: string, username: string|null, password: string|null}  $config
     */
    private function openCodeHttp(array $config): PendingRequest
    {
        $request = Http::timeout(10)
            ->connectTimeout(2)
            ->acceptJson();

        if ($config['username'] !== null && $config['password'] !== null) {
            return $request->withBasicAuth($config['username'], $config['password']);
        }

        return $request;
    }

    private function openCodeUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function normalizeBaseUrl(string $url): string
    {
        return rtrim(str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : "http://{$url}", '/');
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    public function workspaces(array $target, string $adapter): array
    {
        if ($adapter !== 'opencode') {
            return [];
        }

        $app = App::query()
            ->where('name', $target['app'])
            ->first();

        if (! $app instanceof App) {
            return [];
        }

        return Workspace::query()
            ->where('app_id', $app->id)
            ->whereNotNull('agent_ide_workspace_id')
            ->pluck('name')
            ->all();
    }

    /**
     * @param  array{app: string, workspace: string|null, node: string}  $target
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failureMeta(array $target, string $adapter, array $extra = []): array
    {
        return array_merge([
            'app' => $target['app'],
            'workspace' => $target['workspace'],
            'adapter' => $adapter,
        ], $extra);
    }
}
