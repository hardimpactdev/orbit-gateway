<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\WorkspaceSourceDriver;
use App\Data\RemoteShell\RemoteShellResult;
use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use JsonException;
use Polyscope\Laravel\Polyscope;
use Throwable;

final readonly class PolyscopeWorkspaceDriver implements WorkspaceSourceDriver
{
    private const string CONFIG_LOOKUP_UNPARSEABLE = 'Polyscope config lookup returned unparseable output.';

    private const string CONFIG_LOOKUP_FAILED = 'Polyscope configuration lookup failed.';

    private const array SAFE_CONFIG_LOOKUP_ERROR_CODES = [
        'adapter_database_missing',
        'adapter_database_query_failed',
        'adapter_database_unreadable',
        'adapter_settings_invalid',
        'adapter_settings_missing',
        'adapter_settings_unreadable',
        'home_directory_unavailable',
        'invalid_token',
        'missing_token',
        'validation_failed',
    ];

    public function __construct(
        private PolyscopeWorkspaceBranchAligner $branchAligner,
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function create(App $app, Node $node, string $name, string $base): WorkspaceProvisionResult
    {
        $config = $this->resolveConfig($app, $node);
        $client = new Polyscope($config['api_token'], baseUrl: $config['base_url']);

        try {
            $workspace = $client->createWorkspace([
                'server_id' => $config['server_id'],
                'repository_id' => $config['repository_id'],
                'branch' => $name,
                'base_branch' => $base,
            ]);
        } catch (Throwable $exception) {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'Polyscope could not create the workspace.',
                [
                    'adapter' => 'polyscope',
                    'node' => $node->name,
                    'app' => $app->name,
                    'reason' => $exception->getMessage(),
                ],
            );
        }

        if (! is_string($workspace->id) || $workspace->id === '') {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'Polyscope did not return a workspace id.',
                ['adapter' => 'polyscope', 'node' => $node->name, 'app' => $app->name],
            );
        }

        if (! is_string($workspace->path) || $workspace->path === '') {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_create_failed',
                'Polyscope did not return a workspace path.',
                ['adapter' => 'polyscope', 'node' => $node->name, 'app' => $app->name],
            );
        }

        if ($workspace->branch !== $name) {
            try {
                $this->branchAligner->align($node, $workspace->id, $workspace->path, $name);
            } catch (WorkspaceCreateFailed $exception) {
                try {
                    $client->deleteWorkspace($workspace->id);
                } catch (Throwable) {
                    // Best-effort cleanup after a post-create alignment failure.
                }

                throw $exception;
            }
        }

        return new WorkspaceProvisionResult(
            name: $name,
            path: $workspace->path,
            agentIde: 'polyscope',
            agentIdeWorkspaceId: $workspace->id,
        );
    }

    /**
     * @return array{api_token: string, server_id: string, repository_id: string, base_url: string|null}
     */
    private function resolveConfig(App $app, Node $node): array
    {
        $nodeConfig = is_array($node->agent_ide_config) ? $node->agent_ide_config : [];
        $appConfig = is_array($app->agent_ide_config) ? $app->agent_ide_config : [];
        $polyscopeNodeConfig = is_array($nodeConfig['polyscope'] ?? null) ? $nodeConfig['polyscope'] : [];
        $polyscopeAppConfig = is_array($appConfig['polyscope'] ?? null) ? $appConfig['polyscope'] : [];

        $config = [
            'api_token' => $this->stringValue($polyscopeNodeConfig['api_token'] ?? null)
                ?? $this->stringValue($polyscopeNodeConfig['api_key'] ?? null)
                ?? $this->stringValue($polyscopeNodeConfig['auth_token'] ?? null),
            'server_id' => $this->stringValue($polyscopeNodeConfig['server_id'] ?? null),
            'repository_id' => $this->stringValue($polyscopeAppConfig['repository_id'] ?? null),
            'base_url' => $this->stringValue($polyscopeNodeConfig['base_url'] ?? null),
        ];

        if ($config['api_token'] !== null && $config['server_id'] !== null && $config['repository_id'] !== null) {
            return $config;
        }

        $remoteConfig = $this->readRemoteConfig($app, $node);

        $config = [
            'api_token' => $config['api_token'] ?? $remoteConfig['api_token'],
            'server_id' => $config['server_id'] ?? $remoteConfig['server_id'],
            'repository_id' => $config['repository_id'] ?? $remoteConfig['repository_id'],
            'base_url' => $config['base_url'] ?? $remoteConfig['base_url'],
        ];

        if ($config['api_token'] === null || $config['server_id'] === null || $config['repository_id'] === null) {
            throw new WorkspaceCreateFailed(
                'workspace.agent_ide_not_configured',
                'Polyscope is not configured for this app node and repository.',
                [
                    'adapter' => 'polyscope',
                    'node' => $node->name,
                    'app' => $app->name,
                    'missing' => array_values(array_filter([
                        $config['api_token'] === null ? 'api_token' : null,
                        $config['server_id'] === null ? 'server_id' : null,
                        $config['repository_id'] === null ? 'repository_id' : null,
                    ])),
                ],
            );
        }

        return $config;
    }

    /**
     * @return array{api_token: string|null, server_id: string|null, repository_id: string|null, base_url: string|null}
     */
    private function readRemoteConfig(App $app, Node $node): array
    {
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:workspace-adapter:lookup',
            arguments: [],
            commandOptions: [
                'adapter' => 'polyscope',
                'lookup' => 'config',
                'app-path' => $app->path,
            ],
            transportOptions: [
                'timeout' => 30,
                'redact_stdout' => true,
                'redact_stderr' => true,
            ],
        );

        $payload = $this->configLookupPayload($result, $app, $node);

        return [
            'api_token' => $this->stringValue($payload['api_token'] ?? null),
            'server_id' => $this->stringValue($payload['server_id'] ?? null),
            'repository_id' => $this->stringValue($payload['repository_id'] ?? null),
            'base_url' => $this->stringValue($payload['base_url'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function configLookupPayload(RemoteShellResult $result, App $app, Node $node): array
    {
        $envelope = $this->configLookupEnvelope($result, $app, $node);

        if (! is_array($envelope['success'] ?? null)) {
            throw $this->configLookupFailure($envelope, $app, $node);
        }

        if (! is_array($envelope['success']['data'] ?? null)) {
            throw $this->unparseableConfigLookup($app, $node);
        }

        return $envelope['success']['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function configLookupEnvelope(RemoteShellResult $result, App $app, Node $node): array
    {
        try {
            $decoded = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->unparseableConfigLookup($app, $node);
        }

        if (! is_array($decoded) || ! (array_key_exists('success', $decoded) || array_key_exists('error', $decoded))) {
            throw $this->unparseableConfigLookup($app, $node);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function configLookupFailure(array $envelope, App $app, Node $node): WorkspaceCreateFailed
    {
        $error = is_array($envelope['error'] ?? null) ? $envelope['error'] : [];
        $meta = [
            'adapter' => 'polyscope',
            'node' => $node->name,
            'app' => $app->name,
            'reason' => self::CONFIG_LOOKUP_FAILED,
        ];
        $code = $this->safeConfigLookupErrorCode($error['code'] ?? null);

        if ($code !== null) {
            $meta['adapter_error_code'] = $code;
        }

        return new WorkspaceCreateFailed(
            'workspace.agent_ide_not_configured',
            'Polyscope configuration could not be read from the app node.',
            $meta,
        );
    }

    private function safeConfigLookupErrorCode(mixed $value): ?string
    {
        $code = $this->stringValue($value);

        if ($code === null) {
            return null;
        }

        return in_array($code, self::SAFE_CONFIG_LOOKUP_ERROR_CODES, true) ? $code : null;
    }

    private function unparseableConfigLookup(App $app, Node $node): WorkspaceCreateFailed
    {
        return new WorkspaceCreateFailed(
            'workspace.agent_ide_not_configured',
            self::CONFIG_LOOKUP_UNPARSEABLE,
            [
                'adapter' => 'polyscope',
                'node' => $node->name,
                'app' => $app->name,
                'reason' => self::CONFIG_LOOKUP_UNPARSEABLE,
            ],
        );
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
