<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\App;
use App\Models\AppWebSocketBinding;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppWebSocketBinding>
 */
class AppWebSocketBindingFactory extends Factory
{
    protected $model = AppWebSocketBinding::class;

    public function definition(): array
    {
        $slug = Str::slug(fake()->unique()->domainWord());

        return [
            'app_id' => App::factory(),
            'enabled' => true,
            'reverb_app_id' => $slug,
            'reverb_app_key' => Str::random(32),
            'reverb_app_secret' => Str::random(48),
            'allowed_origins' => ["https://{$slug}.example.com"],
            'public_hosts' => ["ws.{$slug}.example.com"],
        ];
    }
}
