<?php

declare(strict_types=1);

use App\Data\Apps\LaravelCloudAppInstanceDriverConfigData;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores app instances as concrete runtime targets for a logical app', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    $app = App::factory()->for($node, 'node')->create(['name' => 'billing']);

    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'driver' => AppInstanceDriver::Orbit,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: 'app-dev-1',
            path: '/home/orbit/apps/billing',
            document_root: 'public',
            domain: null,
        ),
    ]);

    expect($app->instances()->pluck('name')->all())->toBe(['development'])
        ->and($instance->app->is($app))->toBeTrue()
        ->and($instance->driver)->toBe(AppInstanceDriver::Orbit)
        ->and($instance->driver_config)->toBeInstanceOf(OrbitAppInstanceDriverConfigData::class)
        ->and($instance->driver_config->node)->toBe('app-dev-1');
});

it('keeps instance names unique per app only', function (): void {
    $first = App::factory()->create(['name' => 'billing']);
    $second = App::factory()->create(['name' => 'crm']);

    AppInstance::factory()->for($first)->create(['name' => 'production']);
    AppInstance::factory()->for($second)->create(['name' => 'production']);

    expect(fn () => AppInstance::factory()->for($first)->create(['name' => 'production']))
        ->toThrow(QueryException::class);
});

it('hydrates driver_config through Laravel Data concrete classes', function (): void {
    $app = App::factory()->create(['name' => 'billing']);

    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'production-cloud',
        'driver' => AppInstanceDriver::LaravelCloud,
        'driver_config' => new LaravelCloudAppInstanceDriverConfigData(
            organization_id: 'org_123',
            organization_name: 'Platform 11',
            application_id: 'app_123',
            application_name: 'billing',
            environment_id: 'env_123',
            environment_name: 'production',
            domain: 'platform11.nl',
        ),
    ])->fresh();

    expect($instance)->toBeInstanceOf(AppInstance::class)
        ->and($instance->driver_config)->toBeInstanceOf(LaravelCloudAppInstanceDriverConfigData::class)
        ->and($instance->driver_config->environment_name)->toBe('production');

    $stored = json_decode((string) DB::table('app_instances')->where('id', $instance->id)->value('driver_config'), true, flags: JSON_THROW_ON_ERROR);

    expect($stored['type'])->toBe('laravel_cloud_app_instance_driver_config')
        ->and($stored['data']['application_id'])->toBe('app_123');
});
