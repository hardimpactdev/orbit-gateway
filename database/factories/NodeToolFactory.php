<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodeTool>
 */
class NodeToolFactory extends Factory
{
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'name' => 'php',
            'expected_state' => 'installed',
            'expected_version' => null,
            'config' => [
                'endpoints' => [],
            ],
            'credentials' => null,
        ];
    }
}
