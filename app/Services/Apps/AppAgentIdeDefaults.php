<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\App;
use App\Services\AgentIde\AgentIdeAdapterRegistry;
use App\Services\Nodes\NodeAgentIdeDefaults;

final readonly class AppAgentIdeDefaults
{
    public function __construct(
        private AgentIdeAdapterRegistry $registry,
    ) {}

    /**
     * @return array{
     *     app: array<string, mixed>,
     *     agent_ide: array{adapter: string|null, source: string, effective_adapter: string|null},
     *     cleanup: array{workspaces_removed: list<string>},
     *     action: string,
     *     previous_adapter: string|null,
     * }
     */
    public function set(App $app, string $adapter): array
    {
        $app->loadMissing('node');

        $previousAdapter = $this->payloadFor($app)['effective_adapter'];
        $currentAdapter = $this->explicitAdapter($app);
        $normalizedAdapter = $adapter === 'inherit' ? null : $adapter;
        $action = $currentAdapter === $normalizedAdapter ? 'converged' : 'set';

        if ($action === 'set') {
            $config = is_array($app->agent_ide_config) ? $app->agent_ide_config : [];

            if ($normalizedAdapter === null) {
                unset($config['adapter']);
            } else {
                $config['adapter'] = $normalizedAdapter;
            }

            $app->agent_ide_config = $config === [] ? null : $config;
            $app->save();
            $app->refresh();
            $app->loadMissing('node');
        }

        return [
            'app' => $this->appPayload($app),
            'agent_ide' => $this->payloadFor($app),
            'cleanup' => ['workspaces_removed' => []],
            'action' => $action,
            'previous_adapter' => $previousAdapter,
        ];
    }

    public function isSupported(string $adapter): bool
    {
        return in_array($adapter, $this->supportedAdapters(), true);
    }

    /**
     * @return list<string>
     */
    public function supportedAdapters(): array
    {
        return $this->registry->supportedInputsForScope('app');
    }

    /**
     * @return array{adapter: string|null, source: string, effective_adapter: string|null}
     */
    public function payloadFor(App $app): array
    {
        $explicitAdapter = $this->explicitAdapter($app);

        if ($explicitAdapter === 'none') {
            return [
                'adapter' => 'none',
                'source' => 'app',
                'effective_adapter' => null,
            ];
        }

        if ($explicitAdapter !== null) {
            return [
                'adapter' => $explicitAdapter,
                'source' => 'app',
                'effective_adapter' => $explicitAdapter,
            ];
        }

        $app->loadMissing('node');

        if ($app->node !== null) {
            $nodeDefault = NodeAgentIdeDefaults::payloadFor($app->node);
            $nodeAdapter = $nodeDefault['adapter'];

            if ($nodeAdapter !== null) {
                return [
                    'adapter' => null,
                    'source' => 'node',
                    'effective_adapter' => $nodeAdapter,
                ];
            }
        }

        return [
            'adapter' => null,
            'source' => 'default',
            'effective_adapter' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'runtime_kind' => $app->runtime_kind->value,
            'php_version' => $app->php_version,
            'worker_enabled' => $app->worker_enabled,
            'worker_config' => is_array($app->worker_config) ? $app->worker_config : null,
            'adopted' => $app->adopted,
        ];
    }

    private function explicitAdapter(App $app): ?string
    {
        $adapter = $app->agent_ide_config['adapter'] ?? null;

        return is_string($adapter) && $adapter !== '' ? $adapter : null;
    }
}
