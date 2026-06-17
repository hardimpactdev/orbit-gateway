<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'schedule_key' => "app:docs:{$name}",
            'name' => $name,
            'scope' => 'app',
            'app_id' => App::factory(),
            'node_id' => null,
            'target_name' => 'docs',
            'interval' => 'every minute',
            'timezone' => 'UTC',
            'execution_type' => 'command',
            'execution_value' => 'php artisan schedule:run',
            'enabled' => true,
            'status' => 'expected',
        ];
    }

    public function forApp(?App $app = null): static
    {
        return $this->state(function (array $attributes) use ($app): array {
            $target = $app ?? App::factory()->create();

            return [
                'schedule_key' => "app:{$target->name}:{$attributes['name']}",
                'scope' => 'app',
                'app_id' => $target->id,
                'node_id' => null,
                'target_name' => $target->name,
            ];
        });
    }

    public function forNode(?Node $node = null): static
    {
        return $this->state(function (array $attributes) use ($node): array {
            $target = $node ?? Node::factory()->create();

            return [
                'schedule_key' => "node:{$target->name}:{$attributes['name']}",
                'scope' => 'node',
                'app_id' => null,
                'node_id' => $target->id,
                'target_name' => $target->name,
            ];
        });
    }

    public function orbit(): static
    {
        return $this->state(fn (array $attributes): array => [
            'schedule_key' => "orbit:gateway:{$attributes['name']}",
            'scope' => 'orbit',
            'app_id' => null,
            'node_id' => null,
            'target_name' => 'gateway',
        ]);
    }
}
