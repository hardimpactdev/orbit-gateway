<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class ToolCredentialsReader
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function read(string $tool, ?string $node = null, ?string $app = null, ?string $instance = null): array|ToolRegistryFailure
    {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'credentials');
        }

        $model = $this->registry->show(tool: $tool, node: $node, app: $app, instance: $instance);

        if ($model instanceof ToolRegistryFailure) {
            return $model;
        }

        if (! $this->catalog->hasCapability($tool, 'credentials')) {
            return ToolRegistryFailure::unsupportedAction($tool, 'credentials');
        }

        $model->loadMissing('node');

        if ($model->node === null) {
            return ToolRegistryFailure::remoteActionFailed($tool, '', 'credentials', 1, 'Target node is missing.');
        }

        $credentials = is_array($model->credentials) ? $model->credentials : [];
        $fields = is_array($credentials['fields'] ?? null) ? $credentials['fields'] : [];

        if ($fields === []) {
            return ToolRegistryFailure::remoteActionFailed($tool, $model->node->name, 'credentials', 1, 'No credentials stored for this tool.');
        }

        return [
            'tool' => $tool,
            'node' => $model->node->name,
            'fields' => $fields,
        ];
    }
}
