<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Services\Apps\AppRuntimeUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function appRuntimeUserTestApp(Node $node, array $overrides = []): App
{
    $app = App::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'environment' => 'production',
        'path' => '/home/docs/app',
        ...$overrides,
    ]);
    $app->setRelation('node', $node);

    return $app;
}

it('resolves production runtime user from a home path', function (): void {
    $node = Node::factory()->appProd()->create(['user' => 'orbit']);
    $app = appRuntimeUserTestApp($node, [
        'environment' => 'production',
        'path' => '/home/docs/app',
    ]);

    expect(app(AppRuntimeUser::class)->forApp($app))->toBe('docs');
});

it('falls back to node user when the app path is not under home', function (): void {
    $node = Node::factory()->appProd()->create(['user' => 'orbit']);
    $app = appRuntimeUserTestApp($node, [
        'environment' => 'production',
        'path' => '/srv/docs',
    ]);

    expect(app(AppRuntimeUser::class)->forApp($app))->toBe('orbit');
});

it('keeps development apps on the node steady-state user', function (): void {
    $node = Node::factory()->appDev()->create(['user' => 'orbit']);
    $app = appRuntimeUserTestApp($node, [
        'environment' => 'development',
        'path' => '/home/docs/app',
    ]);

    expect(app(AppRuntimeUser::class)->forApp($app))->toBe('orbit');
});

it('exposes container users for production apps only', function (): void {
    $productionNode = Node::factory()->appProd()->create(['user' => 'orbit']);
    $developmentNode = Node::factory()->appDev()->create(['user' => 'orbit']);

    $productionApp = appRuntimeUserTestApp($productionNode, [
        'environment' => 'production',
        'path' => '/home/docs/app',
    ]);
    $developmentApp = appRuntimeUserTestApp($developmentNode, [
        'name' => 'docs-dev',
        'environment' => 'development',
        'path' => '/home/docs/app',
    ]);

    $resolver = app(AppRuntimeUser::class);

    expect($resolver->containerUserForApp($productionApp))->toBe('docs')
        ->and($resolver->containerUserForApp($developmentApp))->toBeNull();
});
