<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;

final readonly class ToolRemover
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function remove(string $tool, ?string $node = null, ?string $app = null): array|ToolRegistryFailure
    {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'remove');
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        if (! $this->catalog->hasCapability($tool, 'remove')) {
            return ToolRegistryFailure::unsupportedAction($tool, 'remove');
        }

        $model->loadMissing('node');

        if ($model->node === null) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'remove', 1, 'Target node is missing.');
        }

        $script = $this->catalog->removeScript($tool, is_array($model->config) ? $model->config : []);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'remove');
        }

        $result = $this->remoteShell->run($model->node, $script, ['throw' => false]);

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed($tool, $model->node->name, 'remove', $result->exitCode, trim($result->stderr));
        }

        $model->credentials = null;
        $model->save();
        $model->delete();

        return [
            'name' => $tool,
            'node' => $model->node->name,
        ];
    }
}
