<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Process>
 */
class ProcessFactory extends Factory
{
    protected $model = Process::class;

    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'owner_type' => App::class,
            'owner_id' => App::factory(),
            'name' => fake()->unique()->slug(1),
            'command' => 'php artisan queue:work',
            'restart_policy' => ProcessRestartPolicy::Never,
            'crash_notification' => ProcessCrashNotification::None,
            'runtime' => ProcessRuntime::Systemd,
            'tool' => null,
            'runtime_config' => [],
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    public function forOwner(Model $owner, ?Node $node = null): static
    {
        return $this->state(fn (): array => [
            'node_id' => $node->id ?? $this->nodeIdForOwner($owner),
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'runtime' => $this->runtimeForOwner($owner),
        ]);
    }

    private function runtimeForOwner(Model $owner): ProcessRuntime
    {
        if ($owner instanceof App || $owner instanceof Workspace) {
            return ProcessRuntime::Systemd;
        }

        return ProcessRuntime::Docker;
    }

    private function nodeIdForOwner(Model $owner): int
    {
        if ($owner instanceof Node) {
            return $owner->id;
        }

        if ($owner instanceof NodeRoleAssignment) {
            return $owner->node_id;
        }

        if ($owner instanceof App) {
            return $owner->node_id;
        }

        if ($owner instanceof Workspace) {
            $owner->loadMissing('app');

            if ($owner->app instanceof App) {
                return $owner->app->node_id;
            }
        }

        return Node::factory()->create()->id;
    }
}
