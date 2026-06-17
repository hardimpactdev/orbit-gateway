<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const APP_INSTANCE_API_CALLER_WG_IP = '10.6.0.117';

function createAppInstanceApiCaller(): Node
{
    return Node::factory()->create([
        'name' => 'instance-caller',
        'host' => APP_INSTANCE_API_CALLER_WG_IP,
        'wireguard_address' => APP_INSTANCE_API_CALLER_WG_IP,
    ]);
}

/**
 * @param  list<string>  $permissions
 */
function grantAppInstanceApiAccess(Node $caller, Node $serving, array $permissions = ['app:read', 'app:write']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $serving->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function appInstanceApiJson(string $method, string $uri, array $data = []): TestResponse
{
    return test()->call(
        $method,
        $uri,
        $data,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_INSTANCE_API_CALLER_WG_IP,
        ],
        $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
    );
}

describe('AppInstanceController', function (): void {
    it('adds lists shows and removes app instances', function (): void {
        $caller = createAppInstanceApiCaller();
        $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
        grantAppInstanceApiAccess($caller, $node);
        App::factory()->for($node, 'node')->create([
            'name' => 'billing',
            'path' => '/home/orbit/apps/billing',
            'document_root' => 'public',
        ]);

        $created = appInstanceApiJson('POST', '/api/apps/billing/instances', [
            'name' => 'production-cloud',
            'driver' => 'laravel-cloud',
            'cloud_application_id' => 'app_123',
            'cloud_application_name' => 'billing',
            'cloud_environment_id' => 'env_123',
            'cloud_environment_name' => 'production',
            'domain' => 'platform11.nl',
            'php_extensions' => ['redis', 'intl'],
        ]);

        $created->assertOk()
            ->assertJsonPath('success.data.instance.app', 'billing')
            ->assertJsonPath('success.data.instance.name', 'production-cloud')
            ->assertJsonPath('success.data.instance.driver', 'laravel-cloud')
            ->assertJsonPath('success.data.instance.driver_config.application_id', 'app_123')
            ->assertJsonPath('success.data.instance.runtime.required_php_extensions', ['intl', 'redis'])
            ->assertJsonPath('success.data.cloud_compatibility.extensions.redis.supported', true);

        $list = appInstanceApiJson('GET', '/api/apps/billing/instances');

        $list->assertOk()
            ->assertJsonPath('success.meta.count', 1)
            ->assertJsonPath('success.data.instances.0.name', 'production-cloud');

        $show = appInstanceApiJson('GET', '/api/apps/billing/instances/production-cloud');

        $show->assertOk()
            ->assertJsonPath('success.data.instance.driver_config.environment_name', 'production');

        $withoutForce = appInstanceApiJson('DELETE', '/api/apps/billing/instances/production-cloud');

        $withoutForce->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force');

        $removed = appInstanceApiJson('DELETE', '/api/apps/billing/instances/production-cloud', [
            'destructive_consent' => true,
        ]);

        $removed->assertOk()
            ->assertJsonPath('success.data.result.action', 'removed');

        expect(AppInstance::query()->count())->toBe(0);
    });

    it('stores orbit driver config with node placement and rejects invalid driver config', function (): void {
        $caller = createAppInstanceApiCaller();
        $devNode = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
        $prodNode = Node::factory()->appProd()->create(['name' => 'app-prod-1']);
        grantAppInstanceApiAccess($caller, $devNode);
        $app = App::factory()->for($devNode, 'node')->create(['name' => 'billing']);

        $created = appInstanceApiJson('POST', '/api/apps/billing/instances', [
            'name' => 'production-orbit',
            'driver' => 'orbit',
            'node' => 'app-prod-1',
            'path' => '/srv/billing/current',
            'root' => 'public',
            'domain' => 'billing.example.com',
        ]);

        $created->assertOk()
            ->assertJsonPath('success.data.instance.driver_config.node', 'app-prod-1')
            ->assertJsonPath('success.data.instance.driver_config.path', '/srv/billing/current');

        $instance = $app->instances()->where('name', 'production-orbit')->firstOrFail();

        expect($instance->driver)->toBe(AppInstanceDriver::Orbit)
            ->and($instance->driver_config)->toBeInstanceOf(OrbitAppInstanceDriverConfigData::class)
            ->and($instance->driver_config->node_id)->toBe($prodNode->id);

        $invalid = appInstanceApiJson('POST', '/api/apps/billing/instances', [
            'name' => 'broken-cloud',
            'driver' => 'laravel-cloud',
            'cloud_application_id' => 'app_123',
        ]);

        $invalid->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'cloud_environment');
    });
});
