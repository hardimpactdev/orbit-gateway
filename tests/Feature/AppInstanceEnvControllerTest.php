<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const APP_INSTANCE_ENV_API_CALLER_WG_IP = '10.6.0.118';

function createAppInstanceEnvApiCaller(): Node
{
    return Node::factory()->create([
        'name' => 'instance-env-caller',
        'host' => APP_INSTANCE_ENV_API_CALLER_WG_IP,
        'wireguard_address' => APP_INSTANCE_ENV_API_CALLER_WG_IP,
    ]);
}

/**
 * @param  list<string>  $permissions
 */
function grantAppInstanceEnvApiAccess(Node $caller, Node $serving, array $permissions = ['app:read', 'app:write', 'database:write']): void
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
function appInstanceEnvApiJson(string $method, string $uri, array $data = []): TestResponse
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
            'REMOTE_ADDR' => APP_INSTANCE_ENV_API_CALLER_WG_IP,
        ],
        $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
    );
}

it('sets lists and renders non-secret app instance env values with database attachments', function (): void {
    $caller = createAppInstanceEnvApiCaller();
    $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    grantAppInstanceEnvApiAccess($caller, $node);
    $app = App::factory()->for($node, 'node')->create(['name' => 'billing']);
    $instance = AppInstance::factory()->for($app)->create(['name' => 'development']);
    $connection = DatabaseConnection::factory()->for($node)->create([
        'slug' => 'billing-db',
        'driver' => 'pgsql',
        'host' => 'postgres.internal',
        'port' => 5432,
        'database' => 'billing',
        'username' => 'billing',
        'credentials' => ['password' => 'secret-password'],
    ]);

    $set = appInstanceEnvApiJson('POST', '/api/apps/billing/instances/development/env', [
        'key' => 'APP_DEBUG',
        'value' => 'false',
    ]);

    $set->assertOk()
        ->assertJsonPath('success.data.variable.key', 'APP_DEBUG')
        ->assertJsonPath('success.data.variable.value', 'false');

    $attach = $this->call('POST', '/api/database-connections/billing-db/targets', [
        'app' => 'billing',
        'instance' => 'development',
        'env_prefix' => 'DB',
    ], [], [], ['REMOTE_ADDR' => APP_INSTANCE_ENV_API_CALLER_WG_IP]);

    $attach->assertOk()
        ->assertJsonPath('success.data.connection.targets.0.type', 'app_instance')
        ->assertJsonPath('success.data.connection.targets.0.app', 'billing')
        ->assertJsonPath('success.data.connection.targets.0.instance', 'development');

    $list = appInstanceEnvApiJson('GET', '/api/apps/billing/instances/development/env');

    $list->assertOk()
        ->assertJsonPath('success.data.variables.0.key', 'APP_DEBUG')
        ->assertJsonPath('success.data.variables.0.value', 'false');

    $render = appInstanceEnvApiJson('GET', '/api/apps/billing/instances/development/env/render');

    $render->assertOk()
        ->assertJsonPath('success.data.variables.APP_DEBUG.value', 'false')
        ->assertJsonPath('success.data.variables.DB_CONNECTION.value', 'pgsql')
        ->assertJsonPath('success.data.variables.DB_PASSWORD.secret', true);

    expect($render->getContent())->not->toContain('secret-password')
        ->and($connection->fresh()->instanceTargets()->where('app_instance_id', $instance->id)->exists())->toBeTrue();
});

it('rejects secret env writes until secret storage is designed', function (): void {
    $caller = createAppInstanceEnvApiCaller();
    $node = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    grantAppInstanceEnvApiAccess($caller, $node);
    $app = App::factory()->for($node, 'node')->create(['name' => 'billing']);
    AppInstance::factory()->for($app)->create(['name' => 'development']);

    $response = appInstanceEnvApiJson('POST', '/api/apps/billing/instances/development/env', [
        'key' => 'API_TOKEN',
        'value' => 'secret',
        'secret' => true,
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'secret');
});
