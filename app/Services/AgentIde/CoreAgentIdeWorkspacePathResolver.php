<?php

declare(strict_types=1);

namespace App\Services\AgentIde;

use App\Contracts\AgentIdeWorkspacePathResolver;
use App\Data\AgentIde\WorkspacePathResolution;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Services\RemoteShell\RemoteLocalExecutor;
use JsonException;
use RuntimeException;

final readonly class CoreAgentIdeWorkspacePathResolver implements AgentIdeWorkspacePathResolver
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function resolve(string $adapter, App $app, string $absolutePath): ?WorkspacePathResolution
    {
        return match ($adapter) {
            'opencode' => $this->resolveOpenCode($app, $absolutePath),
            'polyscope' => $this->resolvePolyscope($app, $absolutePath),
            default => null,
        };
    }

    private function resolveOpenCode(App $app, string $absolutePath): ?WorkspacePathResolution
    {
        return $this->resolveWorkspace('opencode', $app, $absolutePath);
    }

    private function resolvePolyscope(App $app, string $absolutePath): ?WorkspacePathResolution
    {
        return $this->resolveWorkspace('polyscope', $app, $absolutePath);
    }

    private function resolveWorkspace(string $adapter, App $app, string $absolutePath): ?WorkspacePathResolution
    {
        $app->loadMissing('node');

        if ($app->node === null) {
            return null;
        }

        $result = $this->localExecutor->runInternal(
            node: $app->node,
            commandName: 'internal:workspace-adapter:lookup',
            arguments: [],
            commandOptions: [
                'adapter' => $adapter,
                'lookup' => 'workspace',
                'workspace-path' => $absolutePath,
                'app-path' => $app->path,
            ],
            transportOptions: ['timeout' => 30],
        );

        if (! $result->successful()) {
            throw new RuntimeException($this->failureReason($result));
        }

        $payload = $this->successPayload($result);

        if (($payload['match'] ?? null) !== true) {
            return null;
        }

        return new WorkspacePathResolution(
            workspaceName: $this->requiredString($payload, 'workspace_name'),
            appSlug: $app->name,
            path: $this->requiredString($payload, 'path'),
            adapterWorkspaceId: $this->requiredString($payload, 'adapter_workspace_id'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(RemoteShellResult $result): array
    {
        try {
            $decoded = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('adapter_invalid_response');
        }

        if (! is_array($decoded) || ! is_array($decoded['success'] ?? null) || ! is_array($decoded['success']['data'] ?? null)) {
            throw new RuntimeException('adapter_invalid_response');
        }

        return $decoded['success']['data'];
    }

    private function failureReason(RemoteShellResult $result): string
    {
        try {
            $decoded = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return trim($result->stderr) ?: trim($result->stdout) ?: 'adapter_unreachable';
        }

        $message = is_array($decoded)
            && is_array($decoded['error'] ?? null)
            && is_string($decoded['error']['message'] ?? null)
            ? trim($decoded['error']['message'])
            : '';

        return $message !== '' ? $message : (trim($result->stderr) ?: trim($result->stdout) ?: 'adapter_unreachable');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new RuntimeException("invalid_response_{$key}");
    }
}
