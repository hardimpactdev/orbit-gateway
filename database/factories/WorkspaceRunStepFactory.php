<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceRunStep>
 */
class WorkspaceRunStepFactory extends Factory
{
    protected $model = WorkspaceRunStep::class;

    public function definition(): array
    {
        return [
            'workspace_run_id' => WorkspaceRun::factory(),
            'workspace_step_id' => WorkspaceStep::factory(),
            'command' => 'composer install',
            'exit_code' => null,
            'output' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
