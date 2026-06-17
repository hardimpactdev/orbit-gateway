<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\WorkspaceStep;
use Illuminate\Database\Eloquent\Collection;

class WorkspaceStepListPayload
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forApp(App $app, WorkspaceLifecyclePhase $phase): array
    {
        /** @var Collection<int, WorkspaceStep> $steps */
        $steps = WorkspaceStep::query()
            ->where('app_id', $app->id)
            ->where('phase', $phase)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $steps
            ->map(fn (WorkspaceStep $step): array => [
                'id' => $step->id,
                'app' => $app->name,
                'phase' => $phase->value,
                'order' => $step->sort_order,
                'command' => $step->command,
                'timeout_seconds' => $step->timeoutSeconds(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function forStep(WorkspaceStep $step): array
    {
        $step->loadMissing('app');

        return [
            'id' => $step->id,
            'app' => $step->app?->name,
            'phase' => $step->phase->value,
            'order' => $step->sort_order,
            'command' => $step->command,
            'timeout_seconds' => $step->timeoutSeconds(),
        ];
    }
}
