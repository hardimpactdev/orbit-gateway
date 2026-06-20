<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Models\WorkspaceStep;
use Illuminate\Support\Facades\DB;

final readonly class RemoveWorkspaceStep
{
    public function handle(WorkspaceStep $step): void
    {
        DB::transaction(function () use ($step): void {
            $sortOrder = $step->sort_order;
            $appId = $step->app_id;
            $phase = $step->phase;

            $step->delete();

            WorkspaceStep::query()
                ->where('app_id', $appId)
                ->where('phase', $phase)
                ->where('sort_order', '>', $sortOrder)
                ->decrement('sort_order');
        });
    }
}
