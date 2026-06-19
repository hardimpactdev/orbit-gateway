<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;
use Illuminate\Support\Carbon;

class WorkspaceLogPayload
{
    /**
     * @return array<string, mixed>
     */
    public function forRun(WorkspaceRun $run): array
    {
        $run->loadMissing(['workspace.app.node', 'runSteps.step']);
        $workspace = $run->workspace;
        $app = $workspace?->app;

        return [
            'id' => $run->id,
            'workspace' => $workspace?->name,
            'app' => $app?->name,
            'node' => $app?->node?->name,
            'type' => $run->phase->value,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->completed_at?->toIso8601String(),
            'duration_ms' => $this->durationMs($run->started_at, $run->completed_at),
            'steps' => $run->runSteps
                ->map(fn (WorkspaceRunStep $step): array => $this->stepPayload($step))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stepPayload(WorkspaceRunStep $step): array
    {
        $stdout = $step->output ?? '';

        $stepName = $step->step instanceof WorkspaceStep ? $step->step->command : "Step {$step->id}";

        return [
            'name' => $stepName,
            'command' => $step->command,
            'status' => $this->stepStatus($step),
            'exit_code' => $step->exit_code,
            'stdout' => $stdout,
            'stderr' => '',
            'stdout_truncated' => str_ends_with($stdout, '[TRUNCATED]'),
            'stderr_truncated' => false,
            'started_at' => $step->started_at?->toIso8601String(),
            'finished_at' => $step->completed_at?->toIso8601String(),
            'duration_ms' => $this->durationMs($step->started_at, $step->completed_at),
        ];
    }

    private function stepStatus(WorkspaceRunStep $step): string
    {
        if ($step->exit_code === null) {
            return 'skipped';
        }

        return $step->exit_code === 0 ? 'success' : 'failure';
    }

    private function durationMs(?Carbon $startedAt, ?Carbon $finishedAt): ?int
    {
        if ($startedAt === null || $finishedAt === null) {
            return null;
        }

        return (int) round($startedAt->diffInMilliseconds($finishedAt));
    }
}
