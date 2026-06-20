<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Node;
use App\Models\SchedulerState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchedulerState>
 */
class SchedulerStateFactory extends Factory
{
    protected $model = SchedulerState::class;

    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'heartbeat_at' => null,
            'registry_synced_at' => null,
        ];
    }
}
