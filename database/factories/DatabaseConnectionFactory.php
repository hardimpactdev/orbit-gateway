<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DatabaseConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseConnection>
 */
class DatabaseConnectionFactory extends Factory
{
    protected $model = DatabaseConnection::class;

    public function definition(): array
    {
        return [
            'node_id' => null,
            'slug' => fake()->unique()->slug(2),
            'driver' => 'pgsql',
            'host' => fake()->domainName(),
            'port' => 5432,
            'database' => fake()->slug(),
            'path' => null,
            'username' => fake()->userName(),
            'credentials' => ['password' => fake()->password()],
        ];
    }
}
