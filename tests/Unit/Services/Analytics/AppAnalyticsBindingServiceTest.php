<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\AppAnalyticsBinding;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Analytics\AppAnalyticsBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('AppAnalyticsBindingService', function (): void {
    it('creates enabled bindings with a default analytics host derived from the app domain', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();

        $binding = app(AppAnalyticsBindingService::class)->enable($app, []);

        expect($binding)
            ->toBeInstanceOf(AppAnalyticsBinding::class)
            ->and($binding->enabled)->toBeTrue()
            ->and($binding->public_hosts)->toBe(['analytics.docs.test'])
            ->and(ProxyRoute::query()->where('domain', 'analytics.orbit')->where('owner_type', 'router')->exists())->toBeTrue()
            ->and(ProxyRoute::query()->where('domain', 'analytics.docs.test')->where('owner_type', 'app-analytics')->exists())->toBeTrue();
    });

    it('normalizes explicit public hosts and preserves existing binding records on re-enable', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();
        $service = app(AppAnalyticsBindingService::class);

        $first = $service->enable($app, [' https://invalid.test ']);
    })->throws(InvalidArgumentException::class, 'Analytics public hosts must be hostnames, not URLs.');

    it('updates existing bindings with normalized explicit public hosts', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();
        $service = app(AppAnalyticsBindingService::class);

        $first = $service->enable($app, []);
        $updated = $service->enable($app, [' Metrics.Docs.Test ', 'metrics.docs.test', '']);

        expect($updated->id)->toBe($first->id)
            ->and($updated->enabled)->toBeTrue()
            ->and($updated->public_hosts)->toBe(['metrics.docs.test'])
            ->and(ProxyRoute::query()->where('domain', 'analytics.docs.test')->where('owner_type', 'app-analytics')->exists())->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'metrics.docs.test')->where('owner_type', 'app-analytics')->exists())->toBeTrue();
    });

    it('disables bindings and removes public analytics routes without removing the private service route', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();
        $service = app(AppAnalyticsBindingService::class);

        $binding = $service->enable($app, ['analytics.docs.test']);
        $disabled = $service->disable($app);

        expect($disabled->id)->toBe($binding->id)
            ->and($disabled->enabled)->toBeFalse()
            ->and($disabled->public_hosts)->toBe([])
            ->and(ProxyRoute::query()->where('domain', 'analytics.docs.test')->where('owner_type', 'app-analytics')->exists())->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'analytics.orbit')->where('owner_type', 'router')->exists())->toBeTrue();
    });
});

function createAnalyticsRoutePrerequisites(): void
{
    Node::factory()->router()->create([
        'name' => 'router-1',
        'wireguard_address' => '10.6.0.2',
    ]);

    Node::factory()->withActiveRole('analytics')->create([
        'name' => 'analytics-1',
        'wireguard_address' => '10.6.0.50',
    ]);
}

function createAnalyticsApp(?string $domain = 'docs.test', bool $withIngress = true): App
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
