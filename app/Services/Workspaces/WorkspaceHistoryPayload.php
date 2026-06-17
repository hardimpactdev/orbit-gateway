<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class WorkspaceHistoryPayload
{
    /**
     * @return array{
     *     runs: list<array<string, mixed>>,
     *     pagination: array<string, mixed>
     * }
     */
    public function forWorkspace(Workspace $workspace, int $limit, ?Carbon $since, ?Carbon $until, bool $limitCapped): array
    {
        $query = WorkspaceRun::query()
            ->with(['workspace.app', 'runSteps'])
            ->where('workspace_id', $workspace->id)
            ->when($since !== null, fn (Builder $query): Builder => $query->where('started_at', '>=', $since))
            ->when($until !== null, fn (Builder $query): Builder => $query->where('started_at', '<', $until));

        $total = (clone $query)->count();

        /** @var Collection<int, WorkspaceRun> $runs */
        $runs = $query
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return [
            'runs' => $runs->map(fn (WorkspaceRun $run): array => $this->runPayload($run))->all(),
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'since' => $since?->toIso8601String(),
                'until' => $until?->toIso8601String(),
                'limit_capped' => $limitCapped,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runPayload(WorkspaceRun $run): array
    {
        $run->loadMissing(['workspace.app', 'runSteps']);
        $workspace = $run->workspace;

        return [
            'id' => $run->id,
            'workspace' => $workspace?->name,
            'app' => $workspace?->app?->name,
            'action' => $run->phase->value,
            'status' => $run->status,
            'triggered_by' => 'unknown',
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->completed_at?->toIso8601String(),
            'lifecycle_phase' => $run->phase->value,
            'error_summary' => $this->errorSummary($run),
        ];
    }

    private function errorSummary(WorkspaceRun $run): ?string
    {
        if ($run->status !== 'failed') {
            return null;
        }

        $failedStep = $run->runSteps
            ->first(fn (WorkspaceRunStep $step): bool => $step->exit_code !== null && $step->exit_code !== 0);

        if (! $failedStep instanceof WorkspaceRunStep || ! is_string($failedStep->output)) {
            return null;
        }

        return str($failedStep->output)->squish()->limit(160)->toString();
    }
}
