<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;

final readonly class ProcessRuntimeUnitResolver
{
    /**
     * @return array{app: App, workspace: Workspace|null, process: Process}|null
     */
    public function resolve(Node $node, string $unitName): ?array
    {
        if (! str_starts_with($unitName, 'orbit_')) {
            return null;
        }

        $parts = explode('_', $unitName);

        if (count($parts) !== 4 || $parts[0] !== 'orbit') {
            return null;
        }

        [, $appName, $scope, $processName] = $parts;

        $app = App::query()
            ->where('node_id', $node->id)
            ->where('name', $appName)
            ->first();

        if (! $app instanceof App) {
            return null;
        }

        $workspace = null;

        if ($scope !== 'main') {
            $workspace = Workspace::query()
                ->where('app_id', $app->id)
                ->where('name', $scope)
                ->first();

            if (! $workspace instanceof Workspace) {
                return null;
            }
        }

        $process = $workspace instanceof Workspace
            ? $workspace->processes()->where('name', $processName)->first()
            : null;

        if (! $process instanceof Process) {
            $process = $app->processes()
                ->where('name', $processName)
                ->first();
        }

        if (! $process instanceof Process) {
            return null;
        }

        return [
            'app' => $app,
            'workspace' => $workspace,
            'process' => $process,
        ];
    }
}
