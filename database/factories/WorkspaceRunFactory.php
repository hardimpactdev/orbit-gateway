<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceRun>
 */
class WorkspaceRunFactory extends Factory
{
    protected $model = WorkspaceRun::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'phase' => WorkspaceLifecyclePhase::Setup,
            'status' => 'pending',
            'step_set_hash' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
