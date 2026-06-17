<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\DeployStep;
use Illuminate\Support\Facades\DB;

final readonly class RemoveDeployStep
{
    public function handle(DeployStep $step): void
    {
        DB::transaction(function () use ($step): void {
            $appId = $step->app_id;
            $order = $step->sort_order;

            $step->delete();

            DeployStep::query()
                ->where('app_id', $appId)
                ->where('sort_order', '>', $order)
                ->orderBy('sort_order')
                ->each(function (DeployStep $step): void {
                    $step->forceFill(['sort_order' => $step->sort_order - 1])->save();
                });
        });
    }
}
