<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FirewallRule;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FirewallRule>
 */
class FirewallRuleFactory extends Factory
{
    protected $model = FirewallRule::class;

    public function definition(): array
    {
        return [
            'node_id' => Node::factory()
                ->appDev()
                ->state([
                    'platform' => 'ubuntu',
                    'status' => 'active',
                ]),
            'name' => fake()->unique()->bothify('firewall-rule-####'),
            'direction' => 'incoming',
            'action' => 'allow',
            'source' => 'any',
            'destination' => null,
            'port' => '443',
            'protocol' => 'tcp',
            'reason' => 'test firewall rule',
            'source_hash' => hash('sha256', fake()->uuid()),
            'address_family' => 'v4',
            'interface' => null,
            'owner' => 'user',
            'protected' => false,
        ];
    }
}
