<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\Process;
use App\Models\Workspace;

class WorkspaceShowPayload
{
    /**
     * @return array{
     *     workspace: array<string, mixed>,
     *     node: array{name: string|null, host: string|null},
     *     inherited_processes: list<array{name: string}>,
     * }
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $workspace->loadMissing(['app.node', 'app.processes']);

        $app = $workspace->app;
        $node = $app?->node;

        return [
            'workspace' => [
                'name' => $workspace->name,
                'app' => $app?->name,
                'node' => $node?->name,
                'path' => $workspace->path,
                'url' => $workspace->url(),
                'php_version' => $workspace->effectivePhpVersion(),
                'php_inherited' => $workspace->php_version === null,
                'agent_ide' => [
                    'adapter' => $workspace->agent_ide === 'none' ? null : $workspace->agent_ide,
                    'workspace_id' => $workspace->agent_ide_workspace_id,
                ],
                'adopted' => false,
                'lifecycle_status' => $workspace->lifecycle_status->value,
            ],
            'node' => [
                'name' => $node?->name,
                'host' => $node?->host,
            ],
            'inherited_processes' => $app?->processes->map(fn (Process $process): array => [
                'name' => $process->name,
            ])->values()->all() ?? [],
        ];
    }
}
