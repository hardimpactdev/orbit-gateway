<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<App>
 */
class AppFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'node_id' => Node::factory(),
            'environment' => 'development',
            'domain' => null,
            'path' => '/home/orbit/apps/'.fake()->unique()->slug(2),
            'document_root' => 'public',
            'repository' => null,
            'php_version' => '8.5',
            'runtime_kind' => AppRuntimeKind::Php,
            'worker_enabled' => false,
            'worker_config' => null,
            'deploy_warmup_paths' => null,
            'adopted' => false,
            'agent_ide_config' => null,
        ];
    }

    public function static(): static
    {
        return $this->state(fn (): array => [
            'runtime_kind' => AppRuntimeKind::Static,
        ]);
    }

    public function workerEnabled(?array $config = null): static
    {
        return $this->state(fn (): array => [
            'worker_enabled' => true,
            'worker_config' => $config ?? [
                'workers' => 'auto',
                'max_requests' => 500,
            ],
        ]);
    }
}
