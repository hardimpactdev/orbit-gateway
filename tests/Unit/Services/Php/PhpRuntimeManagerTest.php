<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Php;

use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Workspace;
use App\Services\Php\PhpRuntimeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('maps PHP runtime view with inherited workspace version', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'php', 'config' => ['versions' => ['8.5'], 'cli_version' => '8.5']]);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.5']);
    Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'php_version' => null]);

    $result = app(PhpRuntimeManager::class)->view(app: 'docs', workspace: 'feature-docs');

    expect($result->failed())->toBeFalse()
        ->and($result->payload['workspace'])->toBe([
            'name' => 'feature-docs',
            'php_version' => '8.5',
            'inherits' => true,
        ]);
});

it('frankenphp selects app runtime from approved image facts', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => ['dunglas/frankenphp:1-php8.5-bookworm'],
            'versions' => [],
            'cli_version' => null,
        ],
    ]);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.4']);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', app: 'docs');

    expect($result->failed())->toBeFalse()
        ->and($app->refresh()->php_version)->toBe('8.5')
        ->and($result->payload['result'])->toMatchArray([
            'target' => 'app',
            'app' => 'docs',
            'version' => '8.5',
            'image' => 'dunglas/frankenphp:1-php8.5-bookworm',
        ]);
});

it('frankenphp exposes available image facts in runtime views', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => ['dunglas/frankenphp:1-php8.5-bookworm'],
            'versions' => [],
            'cli_version' => '8.5',
        ],
    ]);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.5']);

    $result = app(PhpRuntimeManager::class)->view(app: 'docs');

    expect($result->failed())->toBeFalse()
        ->and($result->payload)->toHaveKey('available_images')
        ->and($result->payload['available_images'])->toBe(['8.5'])
        ->and($result->payload)->not->toHaveKey('installed');
});

it('frankenphp rejects app writes when --node does not own the app', function (): void {
    $appNode = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $appNode->id,
        'name' => 'php',
        'config' => [
            'versions' => ['8.5'],
            'cli_version' => '8.5',
        ],
    ]);

    $imageNode = Node::factory()->appDev()->create(['name' => 'image-node']);
    NodeTool::factory()->create([
        'node_id' => $imageNode->id,
        'name' => 'php',
        'config' => [
            'images' => ['dunglas/frankenphp:1-php8.5-bookworm'],
            'versions' => [],
            'cli_version' => null,
        ],
    ]);

    $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id, 'php_version' => '8.4']);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', app: 'docs', node: 'image-node');

    expect($result->failed())->toBeTrue()
        ->and($app->refresh()->php_version)->toBe('8.4')
        ->and($result->failure?->code)->toBe('validation_failed')
        ->and($result->failure?->meta)->toMatchArray([
            'field' => 'node',
            'reason' => 'target_mismatch',
            'node' => 'image-node',
            'app' => 'docs',
            'owning_node' => 'app-1',
        ]);
});

it('rejects CLI PHP selection for versions other than 8.5', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => [
                'dunglas/frankenphp:1-php8.5-bookworm',
                'dunglas/frankenphp:1-php8.4-bookworm',
            ],
            'versions' => ['8.5', '8.4'],
            'cli_version' => '8.5',
        ],
    ]);

    $result = app(PhpRuntimeManager::class)->use(version: '8.4', node: 'app-1', cli: true);

    expect($result->failed())->toBeTrue()
        ->and($tool->refresh()->config['cli_version'])->toBe('8.5')
        ->and($result->failure?->meta)->toMatchArray([
            'field' => 'version',
            'reason' => 'unsupported_cli_version',
            'supported' => ['8.5'],
        ]);
});

it('frankenphp rejects workspace writes when --node does not own the parent app', function (): void {
    $appNode = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $appNode->id,
        'name' => 'php',
        'config' => [
            'versions' => ['8.5'],
            'cli_version' => '8.5',
        ],
    ]);

    $imageNode = Node::factory()->appDev()->create(['name' => 'image-node']);
    NodeTool::factory()->create([
        'node_id' => $imageNode->id,
        'name' => 'php',
        'config' => [
            'images' => ['dunglas/frankenphp:1-php8.5-bookworm'],
            'versions' => [],
            'cli_version' => null,
        ],
    ]);

    $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id, 'php_version' => '8.4']);
    $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'php_version' => '8.4']);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', workspace: 'feature-docs', node: 'image-node');

    expect($result->failed())->toBeTrue()
        ->and($workspace->refresh()->php_version)->toBe('8.4')
        ->and($result->failure?->code)->toBe('validation_failed')
        ->and($result->failure?->meta)->toMatchArray([
            'field' => 'node',
            'reason' => 'target_mismatch',
            'node' => 'image-node',
            'app' => 'docs',
            'owning_node' => 'app-1',
        ]);
});

it('frankenphp rejects host PHP and FPM fallback facts even when legacy version facts exist', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'images' => [
                'php:8.5-fpm-bookworm',
                'php:8.5-cli-bookworm',
                'dunglas/frankenphp:1-php8.5-alpine',
            ],
            'versions' => ['8.5'],
            'cli_version' => '8.5',
        ],
    ]);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.4']);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', app: 'docs');

    expect($result->failed())->toBeTrue()
        ->and($app->refresh()->php_version)->toBe('8.4')
        ->and($result->failure?->code)->toBe('validation_failed')
        ->and($result->failure?->meta)->toMatchArray([
            'field' => 'version',
            'reason' => 'not_installed',
            'node' => 'app-1',
            'version' => '8.5',
            'image' => 'dunglas/frankenphp:1-php8.5-bookworm',
            'rejected_images' => [
                'php:8.5-fpm-bookworm',
                'php:8.5-cli-bookworm',
                'dunglas/frankenphp:1-php8.5-alpine',
            ],
        ]);
});

it('frankenphp rejects legacy versions-only PHP facts without approved image evidence', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'versions' => ['8.5'],
            'cli_version' => '8.5',
        ],
    ]);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.4']);

    $result = app(PhpRuntimeManager::class)->use(version: '8.5', app: 'docs');

    expect($result->failed())->toBeTrue()
        ->and($app->refresh()->php_version)->toBe('8.4')
        ->and($result->failure?->meta)->toMatchArray([
            'field' => 'version',
            'reason' => 'not_installed',
            'version' => '8.5',
            'image' => 'dunglas/frankenphp:1-php8.5-bookworm',
        ]);
});

it('frankenphp rejects workspace inheritance when inherited app version lacks approved image evidence', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'app-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'config' => [
            'versions' => ['8.5'],
            'cli_version' => '8.5',
        ],
    ]);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.5']);
    $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'php_version' => '8.4']);

    $result = app(PhpRuntimeManager::class)->use(version: null, app: 'docs', workspace: 'feature-docs', inherit: true);

    expect($result->failed())->toBeTrue()
        ->and($workspace->refresh()->php_version)->toBe('8.4')
        ->and($result->failure?->meta)->toMatchArray([
            'field' => 'version',
            'reason' => 'not_installed',
            'version' => '8.5',
            'image' => 'dunglas/frankenphp:1-php8.5-bookworm',
        ]);
});
