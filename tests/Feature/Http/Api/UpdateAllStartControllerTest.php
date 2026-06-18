<?php

declare(strict_types=1);

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Api\UpdateAllStartController;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\RequireGrantPermission;
use App\Http\Middleware\WireGuardIdentity;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

const UPDATE_ALL_START_GATEWAY_WG_IP = '10.6.0.45';

beforeEach(function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        'docker run *' => Process::result(output: "runner\n"),
    ]);

    $this->configRoot = sys_get_temp_dir().'/orbit-update-all-start-'.bin2hex(random_bytes(6));
    config()->set('orbit.paths.config_root', $this->configRoot);

    $this->gateway = createTestGatewayNode([
        'name' => 'gateway',
        'host' => 'gateway',
        'wireguard_address' => UPDATE_ALL_START_GATEWAY_WG_IP,
    ]);
});

afterEach(function (): void {
    if (isset($this->configRoot) && is_string($this->configRoot)) {
        File::deleteDirectory($this->configRoot);
    }
});

it('starts a durable update all operation and stores its immutable plan', function (): void {
    $response = updateAllStartRequest([
        'target_version' => '1.2.3',
        'manifest_source' => 'github-release',
        'manifest_version' => '1.2.3',
        'manifest' => updateAllStartManifest(),
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('success.data.operation_run.type', 'update:all')
        ->assertJsonPath('success.data.operation_run.status', 'queued')
        ->assertJsonPath('success.data.update_plan.target_version', '1.2.3')
        ->assertJsonPath('success.data.update_plan.gateway_image', 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->assertJsonPath('success.data.update_plan.manifest_source', 'github-release');

    $operationRunId = $response->json('success.data.operation_run.id');

    expect($operationRunId)->toBeString()
        ->and($response->json('success.data.events_url'))->toBe("/api/operations/{$operationRunId}/events");

    $run = OperationRun::query()->findOrFail($operationRunId);
    $plan = OperationUpdatePlan::query()->where('operation_run_id', $operationRunId)->firstOrFail();

    expect($run->status)->toBe(OperationStatus::Queued)
        ->and($plan->manifest_snapshot)->toBe(updateAllStartManifest())
        ->and($run->events()->pluck('event_type')->all())->toBe(['tree', 'step']);

    Process::assertRan(function ($process) use ($operationRunId): bool {
        $command = (string) $process->command;
        $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $containerArguments = Str::after($command, "'{$gatewayImage}'");

        expect($command)
            ->toContain("'{$gatewayImage}'")
            ->toContain("'--operation-run-id={$operationRunId}'")
            ->not->toContain('--target-image');

        expect($containerArguments)
            ->toContain("'orbit:update-runner'")
            ->toContain("'--operation-run-id={$operationRunId}'")
            ->not->toContain('manifest')
            ->not->toContain('target-image');

        return true;
    });
});

it('rejects unauthenticated start requests', function (): void {
    $this->postJson('/api/update/all/start', [
        'target_version' => '1.2.3',
        'manifest' => updateAllStartManifest(),
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed');
});

it('requires gateway admin authority for non-gateway callers', function (): void {
    Node::factory()->create([
        'name' => 'operator',
        'status' => 'active',
        'wireguard_address' => '10.6.0.90',
    ]);

    updateAllStartRequest([
        'target_version' => '1.2.3',
        'manifest' => updateAllStartManifest(),
    ], remoteAddress: '10.6.0.90')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', '*')
        ->assertJsonPath('error.meta.serving_node', 'gateway');
});

it('allows non-gateway callers with gateway admin authority', function (): void {
    $caller = Node::factory()->create([
        'name' => 'operator',
        'status' => 'active',
        'wireguard_address' => '10.6.0.90',
    ]);
    NodeAccess::query()->create([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $this->gateway->id,
        'permissions' => ['*'],
        'custom_permissions' => ['*'],
    ]);

    updateAllStartRequest([
        'target_version' => '1.2.3',
        'manifest' => updateAllStartManifest(),
    ], remoteAddress: '10.6.0.90')
        ->assertStatus(202)
        ->assertJsonPath('success.data.operation_run.status', 'queued');
});

it('rejects raw request gateway image overrides when overrides are disabled', function (): void {
    config()->set('orbit.updates.allow_request_image_override', false);

    $response = updateAllStartRequest([
        'target_version' => '1.2.3',
        'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:testing@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
        'manifest' => updateAllStartManifest(),
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.reason', 'update_plan_invalid');

    expect($response->json('error.message'))->toContain('gateway image override is disabled')
        ->and(OperationUpdatePlan::query()->count())->toBe(0)
        ->and(OperationRun::query()->first()?->status)->toBe(OperationStatus::Rejected);
});

it('accepts configured local testing gateway image overrides when digest pinned', function (): void {
    config()->set('orbit.updates.allow_request_image_override', true);

    $response = updateAllStartRequest([
        'target_version' => '1.2.3',
        'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:testing@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
        'manifest' => updateAllStartManifest(),
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('success.data.update_plan.gateway_image', 'ghcr.io/hardimpactdev/orbit-gateway:testing@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
});

it('resolves the configured release manifest when the request omits an inline manifest', function (): void {
    $manifest = updateAllStartManifest([
        'version' => '2.0.0',
        'images' => [
            'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway:2.0.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        ],
    ]);

    Http::fake([
        'github.com/*' => Http::response($manifest, 200),
    ]);

    $response = updateAllStartRequest([]);

    $response->assertStatus(202)
        ->assertJsonPath('success.data.update_plan.target_version', '2.0.0')
        ->assertJsonPath('success.data.update_plan.gateway_image', 'ghcr.io/hardimpactdev/orbit-gateway:2.0.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff');

    $operationRunId = $response->json('success.data.operation_run.id');

    expect(OperationUpdatePlan::query()->where('operation_run_id', $operationRunId)->first()?->manifest_snapshot)
        ->toBe($manifest);
});

it('marks the operation failed when the one shot runner cannot be launched', function (): void {
    Process::fake([
        'docker run *' => Process::result(errorOutput: "docker denied\n", exitCode: 1),
    ]);

    $response = updateAllStartRequest([
        'target_version' => '1.2.3',
        'manifest' => updateAllStartManifest(),
    ]);

    $response->assertStatus(500)
        ->assertJsonPath('error.code', 'update_runner_launch_failed');

    $operationRunId = $response->json('error.meta.operation_run_id');
    $run = OperationRun::query()->findOrFail($operationRunId);
    $errorEvent = $run->events()->where('event_type', 'error')->firstOrFail();

    expect($run->status)->toBe(OperationStatus::Failed)
        ->and($run->error['code'])->toBe('update_runner_launch_failed')
        ->and($run->events()->pluck('event_type')->all())->toBe(['tree', 'step', 'step', 'error'])
        ->and($errorEvent->payload)->toMatchArray([
            'message' => 'Update runner launch failed',
            'exit_code' => 1,
            'data' => [
                'reason' => 'update_runner_launch_failed',
            ],
        ])
        ->and(json_encode($errorEvent->payload, JSON_THROW_ON_ERROR))->not->toContain('docker denied')
        ->and(json_encode($errorEvent->payload, JSON_THROW_ON_ERROR))->not->toContain('Failed to launch update runner');
});

it('returns validation errors before creating an operation run', function (): void {
    updateAllStartRequest([
        'target_version' => [],
        'manifest' => 'not-an-array',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'target_version');

    expect(OperationRun::query()->count())->toBe(0);
});

it('declares gateway-wide permission on the start controller', function (): void {
    $attributes = (new ReflectionClass(UpdateAllStartController::class))
        ->getAttributes(RequiresPermission::class);

    expect($attributes)->toHaveCount(1);

    $permission = $attributes[0]->newInstance();

    expect($permission->permission)->toBe('*')
        ->and($permission->servingNode)->toBe(ServingNode::Gateway);
});

it('lives in the logged authenticated gateway API group', function (): void {
    $route = Route::getRoutes()->getByName('api.update.all.start');

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();

    expect($middleware)->toContain(WireGuardIdentity::class)
        ->and($middleware)->toContain(RequireGrantPermission::class)
        ->and($middleware)->toContain(LogActivity::class);
});

/**
 * @param  array<string, mixed>  $payload
 */
function updateAllStartRequest(array $payload, string $remoteAddress = UPDATE_ALL_START_GATEWAY_WG_IP): TestResponse
{
    return test()->call('POST', '/api/update/all/start', $payload, [], [], [
        'REMOTE_ADDR' => $remoteAddress,
    ]);
}

/**
 * @return array<string, mixed>
 */
function updateAllStartManifest(array $overrides = []): array
{
    return array_replace([
        'schema_version' => 1,
        'version' => '1.2.3',
        'source' => 'github-release',
        'images' => [
            'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-amd64',
                'sha256' => str_repeat('b', 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        ],
    ], $overrides);
}
