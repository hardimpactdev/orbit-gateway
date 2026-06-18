<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Models\App;
use App\Models\AppInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppInstance>
 */
class AppInstanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'name' => 'development',
            'driver' => AppInstanceDriver::Orbit,
            'driver_config' => new OrbitAppInstanceDriverConfigData,
            'runtime_requirements' => new AppInstanceRuntimeRequirementsData,
        ];
    }
}
