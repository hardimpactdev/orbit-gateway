<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\NodeTool;

final readonly class ToolPayloadMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(NodeTool $tool): array
    {
        return [
            'name' => $tool->name,
            'node' => $tool->node?->name,
            'expected_state' => $tool->expected_state,
            'observed_state' => null,
            'version' => $tool->expected_version,
            'managed' => true,
            'endpoints' => $this->endpoints($tool),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function endpoints(NodeTool $tool): array
    {
        $endpoints = $tool->config['endpoints'] ?? [];

        return is_array($endpoints) ? array_values($endpoints) : [];
    }
}
