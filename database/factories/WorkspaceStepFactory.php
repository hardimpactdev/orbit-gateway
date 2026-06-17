<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceLifecyclePhase;
use App\Models\App;
use App\Models\WorkspaceStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceStep>
 */
class WorkspaceStepFactory extends Factory
{
    protected $model = WorkspaceStep::class;

    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'phase' => WorkspaceLifecyclePhase::Setup,
            'sort_order' => 1,
            'command' => 'composer install',
            'timeout_seconds' => WorkspaceStep::DEFAULT_TIMEOUT_SECONDS,
        ];
    }
}
