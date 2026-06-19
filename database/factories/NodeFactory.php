<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Node>
 */
class NodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('node-####'),
            'host' => fake()->unique()->bothify('node-####.test'),
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'status' => NodeStatus::Active,
        ];
    }

    public function operator(): static
    {
        return $this->state(fn (): array => [
            'tld' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function withActiveRole(string $role, array $settings = []): static
    {
        return $this->afterCreating(function (Node $node) use ($role, $settings): void {
            NodeRoleAssignment::factory()->create([
                'node_id' => $node->id,
                'role' => $role,
                'status' => NodeRoleStatus::Active,
                'settings' => $settings,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function appDev(array $settings = ['tld' => 'test']): static
    {
        return $this
            ->state(fn (): array => [
                'tld' => $settings['tld'] ?? null,
            ])
            ->withActiveRole('app-dev', $settings);
    }

    public function appProd(): static
    {
        return $this->withActiveRole('app-prod');
    }

    public function gateway(): static
    {
        return $this->withActiveRole('gateway');
    }

    public function vpn(): static
    {
        return $this->withActiveRole('vpn');
    }

    public function router(): static
    {
        return $this->withActiveRole('router');
    }

    public function database(): static
    {
        return $this->withActiveRole('database');
    }

    public function agent(): static
    {
        return $this->withActiveRole('agent');
    }

    public function ingress(): static
    {
        return $this->withActiveRole('ingress');
    }
}
