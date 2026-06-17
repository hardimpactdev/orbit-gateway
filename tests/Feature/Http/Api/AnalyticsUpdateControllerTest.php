<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const ANALYTICS_UPDATE_CALLER_WG_IP = '10.6.0.94';

function createAnalyticsUpdateCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'analytics-updater',
        'host' => ANALYTICS_UPDATE_CALLER_WG_IP,
        'wireguard_address' => ANALYTICS_UPDATE_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'gateway' => Node::factory()->gateway()->create($attributes),
        default => Node::factory()->create($attributes),
    };
}

function createAnalyticsUpdateNode(string $name = 'analytics-1'): Node
{
    return Node::factory()->withActiveRole('analytics')->create([
        'name' => $name,
        'wireguard_address' => '10.6.0.50',
    ]);
}

function createAnalyticsUpdateProcess(Node $node, string $version = '3.2.1'): Process
{
    return Process::factory()->forOwner($node)->create([
        'name' => 'plausible',
        'command' => 'plausible start',
        'runtime' => ProcessRuntime::DockerSwarm,
        'runtime_config' => [
            'definition' => 'plausible',
            'version_family' => $version,
            'version' => $version,
            'image' => "ghcr.io/plausible/community-edition:{$version}",
            'labels' => [
                'orbit.process.definition' => 'plausible',
                'orbit.process.version' => $version,
            ],
        ],
    ]);
}

function grantAnalyticsUpdateAccess(Node $caller, Node $analyticsNode): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $analyticsNode->id,
        'permissions' => ['process:edit'],
        'custom_permissions' => [],
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function postAnalyticsUpdateJson(array $data): TestResponse
{
    return test()->call(
        'POST',
        '/api/analytics/update',
        $data,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => ANALYTICS_UPDATE_CALLER_WG_IP,
        ],
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

describe('AnalyticsUpdateController', function (): void {
    it('updates the Plausible process version for authorized callers', function (): void {
        $caller = createAnalyticsUpdateCallerNode();
        $analyticsNode = createAnalyticsUpdateNode();
        grantAnalyticsUpdateAccess($caller, $analyticsNode);
        $process = createAnalyticsUpdateProcess($analyticsNode);

        $response = postAnalyticsUpdateJson([
            'version' => '3.2.2',
            'node' => 'analytics-1',
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.analytics.node', 'analytics-1')
            ->assertJsonPath('success.data.analytics.process', 'plausible')
            ->assertJsonPath('success.data.analytics.previous_version', '3.2.1')
            ->assertJsonPath('success.data.analytics.version', '3.2.2')
            ->assertJsonPath('success.data.analytics.status', 'updated');

        $runtimeConfig = $process->refresh()->runtime_config;

        expect($runtimeConfig['version'])->toBe('3.2.2')
            ->and($runtimeConfig['version_family'])->toBe('3.2.2')
            ->and($runtimeConfig['image'])->toBe('ghcr.io/plausible/community-edition:3.2.2')
            ->and($runtimeConfig['labels']['orbit.process.definition'])->toBe('plausible')
            ->and($runtimeConfig['labels']['orbit.process.version'])->toBe('3.2.2');
    });

    it('rejects missing versions before mutating Plausible intent', function (): void {
        createAnalyticsUpdateCallerNode(role: 'gateway');
        $analyticsNode = createAnalyticsUpdateNode();
        $process = createAnalyticsUpdateProcess($analyticsNode);

        $response = postAnalyticsUpdateJson([]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'version');

        expect($process->refresh()->runtime_config['version'])->toBe('3.2.1');
    });

    it('fails when no active analytics node can be resolved', function (): void {
        createAnalyticsUpdateCallerNode(role: 'gateway');

        $response = postAnalyticsUpdateJson([
            'version' => '3.2.2',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.prerequisite_failed')
            ->assertJsonPath('error.meta.version', '3.2.2');
    });

    it('rejects callers without process edit permission before mutating Plausible intent', function (): void {
        createAnalyticsUpdateCallerNode();
        $analyticsNode = createAnalyticsUpdateNode();
        $process = createAnalyticsUpdateProcess($analyticsNode);

        $response = postAnalyticsUpdateJson([
            'version' => '3.2.2',
            'node' => 'analytics-1',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'process:edit');

        expect($process->refresh()->runtime_config['version'])->toBe('3.2.1');
    });
});
