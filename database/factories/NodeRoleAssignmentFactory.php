<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodeRoleAssignment>
 */
class NodeRoleAssignmentFactory extends Factory
{
    protected $model = NodeRoleAssignment::class;

    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'role' => 'database',
            'status' => NodeRoleStatus::Active,
            'settings' => [],
            'last_error' => null,
            'converged_at' => now(),
        ];
    }
}
