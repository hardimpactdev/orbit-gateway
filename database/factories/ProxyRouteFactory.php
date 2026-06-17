<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyRoute>
 */
class ProxyRouteFactory extends Factory
{
    protected $model = ProxyRoute::class;

    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'domain' => fake()->unique()->bothify('route-####.test'),
            'app_id' => null,
            'workspace_id' => null,
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => hash('sha256', fake()->uuid()),
            'config' => [
                'upstream' => 'http://127.0.0.1:8080',
            ],
        ];
    }

    public function forApp(?App $app = null): self
    {
        $appId = $app instanceof App ? $app->id : App::factory();

        return $this->state(fn (): array => [
            'app_id' => $appId,
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [],
        ]);
    }
}
