<?php

declare(strict_types=1);

use App\Data\Doctor\ProbeSnapshot;
use App\Enums\AdoptAction;
use App\Models\App;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Proxy\ProxyRouteAdopter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function adoptResultByKey(array $results, string $key): mixed
{
    return collect($results)->first(fn ($r): bool => $r->key === $key);
}

describe('ProxyRouteAdopter', function (): void {
    it('creates custom proxy intent for observed reverse_proxy routes', function (): void {
        $node = createTestAppHostNode();
        $body = "vite.docs.test {\n    reverse_proxy localhost:8080\n}\n";
        $snapshot = new ProbeSnapshot([
            'vite.docs.test' => [
                'hash' => str_repeat('a', 64),
                'body' => $body,
            ],
        ]);

        $results = (new ProxyRouteAdopter)->adopt($node, $snapshot);

        expect(count($results))->toBe(1)
            ->and($results[0]->action)->toBe(AdoptAction::Created)
            ->and($results[0]->key)->toBe('vite.docs.test');

        $route = ProxyRoute::query()->where('domain', 'vite.docs.test')->first();

        expect($route)->not->toBeNull()
            ->and($route->owner_type)->toBe('custom')
            ->and($route->kind)->toBe('proxy')
            ->and($route->config)->toMatchArray([
                'target' => ['type' => 'upstream', 'value' => 'localhost:8080'],
                'upstream' => 'localhost:8080',
            ]);
    });

    it('creates custom redirect intent for observed redir routes', function (): void {
        $node = createTestAppHostNode();
        $body = "old.docs.test {\n    redir https://new.docs.test{uri} 301\n}\n";
        $snapshot = new ProbeSnapshot([
            'old.docs.test' => [
                'hash' => str_repeat('b', 64),
                'body' => $body,
            ],
        ]);

        $results = (new ProxyRouteAdopter)->adopt($node, $snapshot);

        expect(count($results))->toBe(1)
            ->and($results[0]->action)->toBe(AdoptAction::Created);

        $route = ProxyRoute::query()->where('domain', 'old.docs.test')->first();

        expect($route)->not->toBeNull()
            ->and($route->kind)->toBe('redirect')
            ->and($route->config['code'])->toBe(301);
    });

    it('skips routes already in registry', function (): void {
        $node = createTestAppHostNode();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
        ]);
        $snapshot = new ProbeSnapshot([
            'vite.docs.test' => [
                'hash' => str_repeat('a', 64),
                'body' => "vite.docs.test {\n    reverse_proxy localhost:8080\n}\n",
            ],
        ]);

        $results = (new ProxyRouteAdopter)->adopt($node, $snapshot);

        expect(count($results))->toBe(1)
            ->and($results[0]->action)->toBe(AdoptAction::Skipped);
    });

    it('skips routes that conflict with app domains', function (): void {
        $node = createTestAppHostNode();
        App::factory()->create(['node_id' => $node->id, 'domain' => 'docs.test']);
        $snapshot = new ProbeSnapshot([
            'docs.test' => [
                'hash' => str_repeat('a', 64),
                'body' => "docs.test {\n    reverse_proxy localhost:8080\n}\n",
            ],
        ]);

        $results = (new ProxyRouteAdopter)->adopt($node, $snapshot);

        expect(count($results))->toBe(1)
            ->and($results[0]->action)->toBe(AdoptAction::Skipped);
    });

    it('skips routes that match workspace patterns', function (): void {
        $node = createTestAppHostNode(['tld' => 'test']);
        $app = App::factory()->create(['node_id' => $node->id, 'name' => 'docs', 'domain' => 'docs.test']);
        Workspace::factory()->create(['app_id' => $app->id, 'name' => 'feature']);
        $snapshot = new ProbeSnapshot([
            'feature.docs.test' => [
                'hash' => str_repeat('a', 64),
                'body' => "feature.docs.test {\n    reverse_proxy localhost:8080\n}\n",
            ],
        ]);

        $results = (new ProxyRouteAdopter)->adopt($node, $snapshot);

        expect(count($results))->toBe(1)
            ->and($results[0]->action)->toBe(AdoptAction::Skipped);
    });

    it('skips routes with root directive', function (): void {
        $node = createTestAppHostNode();
        $snapshot = new ProbeSnapshot([
            'docs.test' => [
                'hash' => str_repeat('a', 64),
                'body' => "docs.test {\n    root * /home/orbit/apps/docs/public\n}\n",
            ],
        ]);

        $results = (new ProxyRouteAdopter)->adopt($node, $snapshot);

        expect(count($results))->toBe(1)
            ->and($results[0]->action)->toBe(AdoptAction::Skipped);
    });

    it('skips internal ip-address routes', function (): void {
        $node = createTestAppHostNode();
        $snapshot = new ProbeSnapshot([
            '10.6.0.2' => [
                'hash' => str_repeat('a', 64),
                'body' => "https://10.6.0.2 {\n    reverse_proxy localhost:8080\n}\n",
            ],
        ]);

        $results = (new ProxyRouteAdopter)->adopt($node, $snapshot);

        expect(count($results))->toBe(1)
            ->and($results[0]->action)->toBe(AdoptAction::Skipped);
    });

    it('skips unclassifiable routes', function (): void {
        $node = createTestAppHostNode();
        $snapshot = new ProbeSnapshot([
            'weird.test' => [
                'hash' => str_repeat('a', 64),
                'body' => "weird.test {\n    respond \"Hello\"\n}\n",
            ],
        ]);

        $results = (new ProxyRouteAdopter)->adopt($node, $snapshot);

        expect(count($results))->toBe(1)
            ->and($results[0]->action)->toBe(AdoptAction::Skipped);
    });
});
