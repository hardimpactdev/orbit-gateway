<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;

final readonly class ToolReconfigurer
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function reconfigure(
        string $tool,
        ?string $node = null,
        ?string $app = null,
        array $config = [],
        ?string $password = null,
    ): array|ToolRegistryFailure {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'reconfigure');
        }

        if (! $this->catalog->hasCapability($tool, 'reconfigure')) {
            return ToolRegistryFailure::unsupportedAction($tool, 'reconfigure');
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        $model->loadMissing('node');

        if ($model->node === null) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'reconfigure', 1, 'Target node is missing.');
        }

        $mergedConfig = array_merge(is_array($model->config) ? $model->config : [], $config);

        if ($password !== null) {
            $mergedConfig['password'] = $password;
        }

        $script = $this->catalog->reconfigureScript($tool, $mergedConfig);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'reconfigure');
        }

        $model->config = $mergedConfig;

        if ($password !== null) {
            $existingCreds = is_array($model->credentials) ? $model->credentials : [];
            $model->credentials = array_merge($existingCreds, ['password' => $password]);
        }

        $model->save();

        $result = $this->remoteShell->run($model->node, $script, ['throw' => false]);

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $model->node->name,
                'reconfigure',
                $result->exitCode,
                trim($result->stderr),
            );
        }

        return [
            'name' => $tool,
            'node' => $model->node->name,
            'action' => 'reconfigured',
        ];
    }
}
