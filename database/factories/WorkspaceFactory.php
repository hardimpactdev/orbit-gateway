<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'app_id' => App::factory(),
            'name' => $name,
            'path' => "/home/orbit/apps/docs/workspaces/{$name}",
            'php_version' => null,
            'agent_ide' => null,
            'agent_ide_workspace_id' => null,
            'lifecycle_status' => WorkspaceLifecycleStatus::Expected,
        ];
    }
}
