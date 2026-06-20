<?php

declare(strict_types=1);

use App\Contracts\SiteCertificateInstaller;
use App\Models\App;
use App\Models\AppWebSocketBinding;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\ProxyRoute;
use App\Services\WebSockets\WebSocketBindingService;
use App\Services\WebSockets\WebSocketRuntimeAppConfigSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

const APP_WEBSOCKET_CALLER_WG_IP = '10.6.0.92';

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
    $this->mock(WebSocketRuntimeAppConfigSyncer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sync')->zeroOrMoreTimes();
    });
});

function createAppWebSocketCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'websocket-caller',
        'host' => APP_WEBSOCKET_CALLER_WG_IP,
        'wireguard_address' => APP_WEBSOCKET_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'gateway' => Node::factory()->gateway()->create($attributes),
        default => Node::factory()->create($attributes),
    };
}

/**
 * @param  list<string>  $permissions
 */
function grantAppWebSocketAccess(Node $caller, Node $appNode, array $permissions = ['app:write']): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => $permissions,
        'custom_permissions' => [],
    ]);
}

function createAppWebSocketRoutePrerequisites(bool $withRouter = true, bool $withWebSocket = true): void
{
    if ($withRouter) {
        Node::factory()->router()->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    }

    if ($withWebSocket) {
        Node::factory()->withActiveRole('websocket')->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.4',
        ]);
    }
}

function createAppWebSocketApp(?string $domain = 'docs.test', bool $withIngress = true): App
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
function postAppWebSocketEnableJson(string $uri, array $data): TestResponse
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
            'REMOTE_ADDR' => APP_WEBSOCKET_CALLER_WG_IP,
        ],
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

function getAppWebSocketCredentialsJson(string $uri): TestResponse
{
    return test()->call(
        'GET',
        $uri,
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_WEBSOCKET_CALLER_WG_IP,
        ],
    );
}

function postAppWebSocketDisableJson(string $uri): TestResponse
{
    return test()->call(
        'POST',
        $uri,
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => APP_WEBSOCKET_CALLER_WG_IP,
        ],
    );
}

describe('AppWebSocketController', function (): void {
    it('enables app websocket bindings for authorized callers', function (): void {
        $caller = createAppWebSocketCallerNode();
        createAppWebSocketRoutePrerequisites();
        $app = createAppWebSocketApp();
        grantAppWebSocketAccess($caller, $app->node);

        $response = postAppWebSocketEnableJson('/api/apps/docs/websocket/enable', [
            'public_hosts' => ['ws.docs.test', 'events.docs.test'],
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.binding.app', 'docs')
            ->assertJsonPath('success.data.binding.internal_host', 'websocket.orbit')
            ->assertJsonPath('success.data.binding.public_hosts', ['ws.docs.test', 'events.docs.test'])
            ->assertJsonPath('success.data.binding.allowed_origins', ['https://docs.test'])
            ->assertJsonMissingPath('success.data.binding.reverb_app_secret')
            ->assertJsonMissingPath('success.data.binding.reverb_app_key');

        expect(AppWebSocketBinding::query()->where('app_id', $app->id)->where('enabled', true)->exists())->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'websocket.orbit')->where('owner_type', 'router')->exists())->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'ws.docs.test')->where('owner_type', 'app-websocket')->exists())->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'events.docs.test')->where('owner_type', 'app-websocket')->exists())->toBeTrue();
    });

    it('rejects callers without app write permission before mutation', function (): void {
        $caller = createAppWebSocketCallerNode();
        createAppWebSocketRoutePrerequisites();
        $app = createAppWebSocketApp();
        grantAppWebSocketAccess($caller, $app->node, ['app:read']);

        $response = postAppWebSocketEnableJson('/api/apps/docs/websocket/enable', [
            'public_hosts' => ['ws.docs.test'],
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:write')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect(AppWebSocketBinding::query()->count())->toBe(0);
    });

    it('validates the public host payload before mutation', function (): void {
        createAppWebSocketCallerNode(role: 'gateway');
        createAppWebSocketRoutePrerequisites();
        createAppWebSocketApp();

        $response = postAppWebSocketEnableJson('/api/apps/docs/websocket/enable', [
            'public_hosts' => 'ws.docs.test',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'public_hosts');

        expect(AppWebSocketBinding::query()->count())->toBe(0);
    });

    it('fails when no active websocket backend exists', function (): void {
        createAppWebSocketCallerNode(role: 'gateway');
        createAppWebSocketRoutePrerequisites(withWebSocket: false);
        createAppWebSocketApp();

        $response = postAppWebSocketEnableJson('/api/apps/docs/websocket/enable', [
            'public_hosts' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'websocket.prerequisite_failed')
            ->assertJsonPath('error.meta.app', 'docs');

        expect(AppWebSocketBinding::query()->count())->toBe(0);
    });

    it('fails when public hosts are requested for an app without ingress', function (): void {
        createAppWebSocketCallerNode(role: 'gateway');
        createAppWebSocketRoutePrerequisites();
        createAppWebSocketApp(withIngress: false);

        $response = postAppWebSocketEnableJson('/api/apps/docs/websocket/enable', [
            'public_hosts' => ['ws.docs.test'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'websocket.prerequisite_failed')
            ->assertJsonPath('error.meta.app', 'docs');

        expect(AppWebSocketBinding::query()->count())->toBe(0)
            ->and(ProxyRoute::query()->where('owner_type', 'app-websocket')->exists())->toBeFalse();
    });

    it('returns app not found for unknown app selectors', function (): void {
        createAppWebSocketCallerNode(role: 'gateway');
        createAppWebSocketRoutePrerequisites();

        $response = postAppWebSocketEnableJson('/api/apps/missing/websocket/enable', [
            'public_hosts' => [],
        ]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'app.not_found')
            ->assertJsonPath('error.meta.app', 'missing');
    });

    it('returns app websocket credentials for authorized callers', function (): void {
        $caller = createAppWebSocketCallerNode();
        createAppWebSocketRoutePrerequisites();
        $app = createAppWebSocketApp();
        grantAppWebSocketAccess($caller, $app->node, ['app:credentials']);

        $binding = app(WebSocketBindingService::class)->enable($app, ['ws.docs.test']);

        $response = getAppWebSocketCredentialsJson('/api/apps/docs/websocket/credentials');

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.app', 'docs')
            ->assertJsonPath('success.data.credentials.internal_host', 'websocket.orbit')
            ->assertJsonPath('success.data.credentials.public_hosts', ['ws.docs.test'])
            ->assertJsonPath('success.data.credentials.allowed_origins', ['https://docs.test'])
            ->assertJsonPath('success.data.credentials.reverb_app_id', 'docs')
            ->assertJsonPath('success.data.credentials.reverb_app_key', $binding->reverb_app_key)
            ->assertJsonPath('success.data.credentials.reverb_app_secret', $binding->reverb_app_secret);
    });

    it('requires the explicit app credentials permission for credential reads', function (): void {
        $caller = createAppWebSocketCallerNode();
        createAppWebSocketRoutePrerequisites();
        $app = createAppWebSocketApp();
        grantAppWebSocketAccess($caller, $app->node, ['app:write']);

        app(WebSocketBindingService::class)->enable($app, ['ws.docs.test']);

        $response = getAppWebSocketCredentialsJson('/api/apps/docs/websocket/credentials');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:credentials')
            ->assertJsonPath('error.meta.serving_node', 'app-1');
    });

    it('fails when credentials are requested before the app has an enabled binding', function (): void {
        $caller = createAppWebSocketCallerNode();
        createAppWebSocketRoutePrerequisites();
        $app = createAppWebSocketApp();
        grantAppWebSocketAccess($caller, $app->node, ['app:credentials']);

        $response = getAppWebSocketCredentialsJson('/api/apps/docs/websocket/credentials');

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'websocket.binding_missing')
            ->assertJsonPath('error.meta.app', 'docs');
    });

    it('returns app not found for unknown credential app selectors', function (): void {
        createAppWebSocketCallerNode(role: 'gateway');
        createAppWebSocketRoutePrerequisites();

        $response = getAppWebSocketCredentialsJson('/api/apps/missing/websocket/credentials');

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'app.not_found')
            ->assertJsonPath('error.meta.app', 'missing');
    });

    it('disables app websocket bindings for authorized callers', function (): void {
        $caller = createAppWebSocketCallerNode();
        createAppWebSocketRoutePrerequisites();
        $app = createAppWebSocketApp();
        grantAppWebSocketAccess($caller, $app->node);

        $binding = app(WebSocketBindingService::class)->enable($app, ['ws.docs.test']);
        $reverbAppKey = $binding->reverb_app_key;
        $reverbAppSecret = $binding->reverb_app_secret;

        expect(ProxyRoute::query()->where('domain', 'ws.docs.test')->where('owner_type', 'app-websocket')->exists())->toBeTrue();

        $response = postAppWebSocketDisableJson('/api/apps/docs/websocket/disable');

        $response->assertOk()
            ->assertJsonPath('success.data.binding.app', 'docs')
            ->assertJsonPath('success.data.binding.internal_host', 'websocket.orbit')
            ->assertJsonPath('success.data.binding.public_hosts', [])
            ->assertJsonPath('success.data.binding.allowed_origins', ['https://docs.test'])
            ->assertJsonMissingPath('success.data.binding.reverb_app_secret')
            ->assertJsonMissingPath('success.data.binding.reverb_app_key');

        $disabled = $binding->refresh();

        expect($disabled->enabled)->toBeFalse()
            ->and($disabled->public_hosts)->toBe([])
            ->and($disabled->reverb_app_key)->toBe($reverbAppKey)
            ->and($disabled->reverb_app_secret)->toBe($reverbAppSecret)
            ->and(ProxyRoute::query()->where('domain', 'ws.docs.test')->exists())->toBeFalse();
    });

    it('rejects websocket disable callers without app write permission before mutation', function (): void {
        $caller = createAppWebSocketCallerNode();
        createAppWebSocketRoutePrerequisites();
        $app = createAppWebSocketApp();
        grantAppWebSocketAccess($caller, $app->node, ['app:credentials']);

        $binding = app(WebSocketBindingService::class)->enable($app, ['ws.docs.test']);

        $response = postAppWebSocketDisableJson('/api/apps/docs/websocket/disable');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:write')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect($binding->refresh()->enabled)->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'ws.docs.test')->where('owner_type', 'app-websocket')->exists())->toBeTrue();
    });

    it('fails when websocket disable is requested before the app has a binding', function (): void {
        $caller = createAppWebSocketCallerNode();
        createAppWebSocketRoutePrerequisites();
        $app = createAppWebSocketApp();
        grantAppWebSocketAccess($caller, $app->node);

        $response = postAppWebSocketDisableJson('/api/apps/docs/websocket/disable');

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'websocket.binding_missing')
            ->assertJsonPath('error.meta.app', 'docs');
    });

    it('returns app not found for unknown disable app selectors', function (): void {
        createAppWebSocketCallerNode(role: 'gateway');
        createAppWebSocketRoutePrerequisites();

        $response = postAppWebSocketDisableJson('/api/apps/missing/websocket/disable');

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'app.not_found')
            ->assertJsonPath('error.meta.app', 'missing');
    });
});
