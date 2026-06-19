<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\WorkspaceStep;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class AddWorkspaceStep
{
    public function handle(
        int $appId,
        WorkspaceLifecyclePhase $phase,
        string $command,
        int $timeoutSeconds = WorkspaceStep::DEFAULT_TIMEOUT_SECONDS,
        ?int $beforeStepId = null,
        ?int $afterStepId = null,
    ): WorkspaceStep {
        return DB::transaction(function () use ($appId, $phase, $command, $timeoutSeconds, $beforeStepId, $afterStepId): WorkspaceStep {
            $phaseSteps = WorkspaceStep::query()
                ->where('app_id', $appId)
                ->where('phase', $phase);

            if ($beforeStepId !== null) {
                $anchor = (clone $phaseSteps)->find($beforeStepId);

                if (! $anchor instanceof WorkspaceStep) {
                    throw new InvalidArgumentException("Step #{$beforeStepId} was not found.");
                }

                $sortOrder = $anchor->sort_order;
                $phaseSteps->where('sort_order', '>=', $sortOrder)->increment('sort_order');
            } elseif ($afterStepId !== null) {
                $anchor = (clone $phaseSteps)->find($afterStepId);

                if (! $anchor instanceof WorkspaceStep) {
                    throw new InvalidArgumentException("Step #{$afterStepId} was not found.");
                }

                $sortOrder = $anchor->sort_order + 1;
                $phaseSteps->where('sort_order', '>=', $sortOrder)->increment('sort_order');
            } else {
                $sortOrder = ((clone $phaseSteps)->max('sort_order') ?? 0) + 1;
            }

            return WorkspaceStep::query()->create([
                'app_id' => $appId,
                'phase' => $phase,
                'sort_order' => $sortOrder,
                'command' => $command,
                'timeout_seconds' => $timeoutSeconds,
            ]);
        });
    }
}
