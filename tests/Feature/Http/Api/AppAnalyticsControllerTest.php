<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\AppAnalyticsBinding;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\ProxyRoute;
use App\Services\Analytics\AppAnalyticsBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const APP_ANALYTICS_CALLER_WG_IP = '10.6.0.93';

function createAppAnalyticsCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'analytics-caller',
        'host' => APP_ANALYTICS_CALLER_WG_IP,
        'wireguard_address' => APP_ANALYTICS_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'gateway' => Node::factory()->gateway()->create($attributes),
        default => Node::factory()->create($attributes),
    };
}

/**
 * @param  list<string>  $permissions
 */
function grantAppAnalyticsAccess(Node $caller, Node $appNode, array $permissions = ['app:write']): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => $permissions,
        'custom_permissions' => [],
    ]);
}

function createAppAnalyticsRoutePrerequisites(bool $withRouter = true, bool $withAnalytics = true): void
{
    if ($withRouter) {
        Node::factory()->router()->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    }

    if ($withAnalytics) {
        Node::factory()->withActiveRole('analytics')->create([
            'name' => 'analytics-1',
            'wireguard_address' => '10.6.0.50',
        ]);
    }
}

function createAppAnalyticsApp(?string $domain = 'docs.test', bool $withIngress = true): App
{
    $ingress = $withIngress
        ? Node::factory()->ingress()->create([
            'name' => 'edge-1',
            'wireguard_address' => '10.6.0.10',
        ])
        : null;

    $appNode = Node::factory()->appProd()->create([
        'name' => 'app-1',
        'wireguard_address' => '10.6.0.21',
    ]);

    if ($ingress instanceof Node) {
        $appNode->roleAssignments()
            ->where('role', 'app-prod')
            ->update(['settings' => ['ingress_node_id' => $ingress->id]]);
    }

    return App::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'domain' => $domain,
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function postAppAnalyticsEnableJson(string $uri, array $data): TestResponse
{
    return test()->call(
        'POST',
        $uri,
        $data,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_ANALYTICS_CALLER_WG_IP,
        ],
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

function postAppAnalyticsDisableJson(string $uri): TestResponse
{
    return test()->call(
        'POST',
        $uri,
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_ANALYTICS_CALLER_WG_IP,
        ],
    );
}

function getAppAnalyticsJson(string $uri): TestResponse
{
    return test()->call(
        'GET',
        $uri,
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_ANALYTICS_CALLER_WG_IP,
        ],
    );
}

describe('AppAnalyticsController', function (): void {
    it('enables app analytics bindings for authorized callers', function (): void {
        $caller = createAppAnalyticsCallerNode();
        createAppAnalyticsRoutePrerequisites();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node);

        $response = postAppAnalyticsEnableJson('/api/apps/docs/analytics/enable', [
            'public_hosts' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.binding.app', 'docs')
            ->assertJsonPath('success.data.binding.enabled', true)
            ->assertJsonPath('success.data.binding.internal_host', 'analytics.orbit')
            ->assertJsonPath('success.data.binding.dashboard_url', 'https://analytics.orbit')
            ->assertJsonPath('success.data.binding.public_hosts', ['analytics.docs.test'])
            ->assertJsonPath('success.data.binding.tracking_paths', ['/js/*', '/api/event']);

        expect(AppAnalyticsBinding::query()->where('app_id', $app->id)->where('enabled', true)->exists())->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'analytics.orbit')->where('owner_type', 'router')->exists())->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'analytics.docs.test')->where('owner_type', 'app-analytics')->exists())->toBeTrue();
    });

    it('rejects callers without app write permission before mutation', function (): void {
        $caller = createAppAnalyticsCallerNode();
        createAppAnalyticsRoutePrerequisites();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node, ['app:read']);

        $response = postAppAnalyticsEnableJson('/api/apps/docs/analytics/enable', [
            'public_hosts' => ['analytics.docs.test'],
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:write');

        expect(AppAnalyticsBinding::query()->count())->toBe(0);
    });

    it('fails when no active analytics backend exists', function (): void {
        createAppAnalyticsCallerNode(role: 'gateway');
        createAppAnalyticsRoutePrerequisites(withAnalytics: false);
        createAppAnalyticsApp();

        $response = postAppAnalyticsEnableJson('/api/apps/docs/analytics/enable', [
            'public_hosts' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.prerequisite_failed')
            ->assertJsonPath('error.meta.app', 'docs');

        expect(AppAnalyticsBinding::query()->count())->toBe(0);
    });

    it('disables app analytics bindings for authorized callers', function (): void {
        $caller = createAppAnalyticsCallerNode();
        createAppAnalyticsRoutePrerequisites();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node);

        app(AppAnalyticsBindingService::class)->enable($app, ['analytics.docs.test']);

        $response = postAppAnalyticsDisableJson('/api/apps/docs/analytics/disable');

        $response->assertOk()
            ->assertJsonPath('success.data.binding.app', 'docs')
            ->assertJsonPath('success.data.binding.enabled', false)
            ->assertJsonPath('success.data.binding.public_hosts', []);

        expect(AppAnalyticsBinding::query()->where('app_id', $app->id)->where('enabled', false)->exists())->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'analytics.docs.test')->where('owner_type', 'app-analytics')->exists())->toBeFalse();
    });

    it('shows app analytics bindings for authorized callers', function (): void {
        $caller = createAppAnalyticsCallerNode();
        createAppAnalyticsRoutePrerequisites();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node, ['app:read']);

        app(AppAnalyticsBindingService::class)->enable($app, ['analytics.docs.test']);

        $response = getAppAnalyticsJson('/api/apps/docs/analytics');

        $response->assertOk()
            ->assertJsonPath('success.data.binding.app', 'docs')
            ->assertJsonPath('success.data.binding.enabled', true)
            ->assertJsonPath('success.data.binding.public_hosts', ['analytics.docs.test']);
    });

    it('returns binding missing when show is requested before enable', function (): void {
        $caller = createAppAnalyticsCallerNode();
        $app = createAppAnalyticsApp();
        grantAppAnalyticsAccess($caller, $app->node, ['app:read']);

        $response = getAppAnalyticsJson('/api/apps/docs/analytics');

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'analytics.binding_missing')
            ->assertJsonPath('error.meta.app', 'docs');
    });
});
