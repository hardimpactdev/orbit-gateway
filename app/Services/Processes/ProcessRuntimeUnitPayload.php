<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;

class ProcessRuntimeUnitPayload
{
    public function __construct(
        private readonly ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @return list<array{name: string, context: string}>
     */
    public function forProcess(App $app, Process $process, ?Workspace $workspaceContext = null): array
    {
        $app->loadMissing('workspaces');
        $process->loadMissing('owner');

        return collect($this->contexts($app, $process, $workspaceContext))
            ->map(fn (?Workspace $workspace): array => [
                'name' => $this->runtimeDrivers->forProcess($process)->runtimeUnitName($app, $process, $workspace),
                'context' => $this->contextName($process, $workspace),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<Workspace|null>
     */
    private function contexts(App $app, Process $process, ?Workspace $workspaceContext): array
    {
        if ($process->owner instanceof Node) {
            return [null];
        }

        if ($workspaceContext instanceof Workspace) {
            return [$workspaceContext];
        }

        if ($process->owner instanceof Workspace) {
            return [$process->owner];
        }

        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $containerName = $config['container_name'] ?? null;

        if (is_string($containerName) && trim($containerName) !== '') {
            return [null];
        }

        return [null, ...$app->workspaces->all()];
    }

    private function contextName(Process $process, ?Workspace $workspace): string
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return 'node';
        }

        return $workspace instanceof Workspace ? $workspace->name : 'main';
    }
}
