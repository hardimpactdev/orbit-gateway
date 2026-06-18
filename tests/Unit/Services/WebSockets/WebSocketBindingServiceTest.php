<?php

declare(strict_types=1);

use App\Contracts\SiteCertificateInstaller;
use App\Models\App;
use App\Models\AppWebSocketBinding;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\WebSockets\WebSocketBindingService;
use App\Services\WebSockets\WebSocketCredentials;
use App\Services\WebSockets\WebSocketRuntimeAppConfigSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Fakes\SiteCertificateInstallerFake;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function websocketBindingServiceApp(?string $domain = 'docs.test', bool $withIngress = true): App
{
    $slug = str_replace('.', '-', $domain ?? 'private-app');

    $ingress = $withIngress
        ? Node::factory()->ingress()->create([
            'name' => "edge-{$slug}",
            'wireguard_address' => '10.6.0.10',
        ])
        : null;

    $appNode = Node::factory()->appProd()->create([
        'name' => "app-prod-{$slug}",
        'wireguard_address' => '10.6.0.21',
    ]);

    if ($ingress instanceof Node) {
        $appNode->roleAssignments()
            ->where('role', 'app-prod')
            ->update(['settings' => ['ingress_node_id' => $ingress->id]]);
    }

    return App::factory()->create([
        'name' => $slug,
        'node_id' => $appNode->id,
        'domain' => $domain,
    ]);
}

function websocketBindingServiceRouteBackends(): void
{
    Node::factory()->router()->create([
        'name' => 'router-1',
        'wireguard_address' => '10.6.0.2',
    ]);

    Node::factory()->withActiveRole('websocket')->create([
        'name' => 'app-dev-1',
        'wireguard_address' => '10.6.0.4',
    ]);
}

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
    $this->mock(WebSocketRuntimeAppConfigSyncer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sync')->zeroOrMoreTimes();
    });
});

it('enables an app websocket binding with generated credentials and synced routes', function (): void {
    websocketBindingServiceRouteBackends();
    $app = websocketBindingServiceApp(domain: 'docs.test');

    $binding = app(WebSocketBindingService::class)->enable($app, [
        ' ws.docs.test ',
        'events.docs.test',
        'ws.docs.test',
        '',
    ]);

    expect($binding)->toBeInstanceOf(AppWebSocketBinding::class)
        ->and($binding->app_id)->toBe($app->id)
        ->and($binding->enabled)->toBeTrue()
        ->and($binding->reverb_app_id)->toBe('docs-test')
        ->and($binding->reverb_app_key)->toHaveLength(32)
        ->and($binding->reverb_app_secret)->toHaveLength(48)
        ->and($binding->allowed_origins)->toBe(['https://docs.test'])
        ->and($binding->public_hosts)->toBe(['ws.docs.test', 'events.docs.test']);

    expect(ProxyRoute::query()->where('domain', 'websocket.orbit')->where('owner_type', 'router')->exists())->toBeTrue()
        ->and(ProxyRoute::query()->where('domain', 'ws.docs.test')->where('owner_type', 'app-websocket')->exists())->toBeTrue()
        ->and(ProxyRoute::query()->where('domain', 'events.docs.test')->where('owner_type', 'app-websocket')->exists())->toBeTrue();
});

it('returns websocket credentials for an enabled binding', function (): void {
    websocketBindingServiceRouteBackends();
    $app = websocketBindingServiceApp(domain: 'docs.test');
    $binding = app(WebSocketBindingService::class)->enable($app, ['ws.docs.test']);

    $credentials = app(WebSocketBindingService::class)->credentials($app);

    expect($credentials)->toBeInstanceOf(WebSocketCredentials::class)
        ->and($credentials->toArray())->toBe([
            'app' => 'docs-test',
            'internal_host' => 'websocket.orbit',
            'public_hosts' => ['ws.docs.test'],
            'allowed_origins' => ['https://docs.test'],
            'reverb_app_id' => 'docs-test',
            'reverb_app_key' => $binding->reverb_app_key,
            'reverb_app_secret' => $binding->reverb_app_secret,
        ]);
});

it('allows private-only bindings for apps without a public domain', function (): void {
    websocketBindingServiceRouteBackends();
    $app = websocketBindingServiceApp(domain: null, withIngress: false);

    $binding = app(WebSocketBindingService::class)->enable($app, []);

    expect($binding->enabled)->toBeTrue()
        ->and($binding->allowed_origins)->toBe([])
        ->and($binding->public_hosts)->toBe([])
        ->and(ProxyRoute::query()->where('domain', 'websocket.orbit')->where('owner_type', 'router')->exists())->toBeTrue()
        ->and(ProxyRoute::query()->where('owner_type', 'app-websocket')->exists())->toBeFalse();
});

it('preserves per-app credentials across disable and re-enable', function (): void {
    websocketBindingServiceRouteBackends();
    $app = websocketBindingServiceApp(domain: 'docs.test');
    $service = app(WebSocketBindingService::class);

    $enabled = $service->enable($app, ['ws.docs.test']);
    $service->disable($app);
    $reenabled = $service->enable($app, ['events.docs.test']);

    expect($reenabled->id)->toBe($enabled->id)
        ->and($reenabled->reverb_app_id)->toBe($enabled->reverb_app_id)
        ->and($reenabled->reverb_app_key)->toBe($enabled->reverb_app_key)
        ->and($reenabled->reverb_app_secret)->toBe($enabled->reverb_app_secret)
        ->and($reenabled->enabled)->toBeTrue()
        ->and($reenabled->public_hosts)->toBe(['events.docs.test']);
});

it('generates different credentials for different apps', function (): void {
    websocketBindingServiceRouteBackends();
    $first = websocketBindingServiceApp(domain: 'docs.test');
    $second = websocketBindingServiceApp(domain: 'api.test');

    $service = app(WebSocketBindingService::class);
    $firstBinding = $service->enable($first, []);
    $secondBinding = $service->enable($second, []);

    expect($firstBinding->reverb_app_id)->not->toBe($secondBinding->reverb_app_id)
        ->and($firstBinding->reverb_app_key)->not->toBe($secondBinding->reverb_app_key)
        ->and($firstBinding->reverb_app_secret)->not->toBe($secondBinding->reverb_app_secret);
});

it('disables a binding and removes public route intent without deleting credentials', function (): void {
    websocketBindingServiceRouteBackends();
    $app = websocketBindingServiceApp(domain: 'docs.test');
    $service = app(WebSocketBindingService::class);
    $enabled = $service->enable($app, ['ws.docs.test']);

    $disabled = $service->disable($app);

    expect($disabled->id)->toBe($enabled->id)
        ->and($disabled->enabled)->toBeFalse()
        ->and($disabled->public_hosts)->toBe([])
        ->and($disabled->reverb_app_id)->toBe($enabled->reverb_app_id)
        ->and($disabled->reverb_app_key)->toBe($enabled->reverb_app_key)
        ->and($disabled->reverb_app_secret)->toBe($enabled->reverb_app_secret)
        ->and(ProxyRoute::query()->where('domain', 'ws.docs.test')->exists())->toBeFalse();
});

it('does not persist bindings when websocket route prerequisites fail', function (): void {
    Node::factory()->router()->create([
        'name' => 'router-1',
        'wireguard_address' => '10.6.0.2',
    ]);
    $app = websocketBindingServiceApp(domain: 'docs.test');

    expect(fn () => app(WebSocketBindingService::class)->enable($app, []))
        ->toThrow(RuntimeException::class, 'The websocket service route requires at least one active websocket backend.');

    expect(AppWebSocketBinding::query()->count())->toBe(0);
});

it('rolls back binding state when public hosts cannot be routed through ingress', function (): void {
    websocketBindingServiceRouteBackends();
    $app = websocketBindingServiceApp(domain: 'docs.test', withIngress: false);

    expect(fn () => app(WebSocketBindingService::class)->enable($app, ['ws.docs.test']))
        ->toThrow(DomainException::class, 'The selected ingress node is unavailable.');

    expect(AppWebSocketBinding::query()->count())->toBe(0)
        ->and(ProxyRoute::query()->where('owner_type', 'app-websocket')->exists())->toBeFalse();
});
