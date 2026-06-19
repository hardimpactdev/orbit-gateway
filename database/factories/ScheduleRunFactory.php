<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Node;
use App\Models\ScheduleRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleRun>
 */
class ScheduleRunFactory extends Factory
{
    protected $model = ScheduleRun::class;

    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'schedule_key' => fake()->unique()->slug(2),
            'status' => 'completed',
            'exit_code' => 0,
            'stdout' => '',
            'stderr' => '',
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
