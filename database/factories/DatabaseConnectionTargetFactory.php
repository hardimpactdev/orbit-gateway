<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseConnectionTarget>
 */
class DatabaseConnectionTargetFactory extends Factory
{
    protected $model = DatabaseConnectionTarget::class;

    public function definition(): array
    {
        return [
            'database_connection_id' => DatabaseConnection::factory(),
            'app_id' => App::factory(),
            'workspace_id' => null,
            'env_prefix' => 'DB',
        ];
    }

    public function forApp(?App $app = null): static
    {
        return $this->state(fn (): array => [
            'app_id' => $app instanceof App ? $app->id : App::factory(),
            'workspace_id' => null,
        ]);
    }

    public function forWorkspace(?Workspace $workspace = null): static
    {
        return $this->state(fn (): array => [
            'app_id' => null,
            'workspace_id' => $workspace instanceof Workspace ? $workspace->id : Workspace::factory(),
        ]);
    }
}
