<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Node;
use App\Models\ScheduleLock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleLock>
 */
class ScheduleLockFactory extends Factory
{
    protected $model = ScheduleLock::class;

    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'schedule_key' => fake()->unique()->slug(2),
            'owner_token' => fake()->uuid(),
            'locked_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ];
    }
}
