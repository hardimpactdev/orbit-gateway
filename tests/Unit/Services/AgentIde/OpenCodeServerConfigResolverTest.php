<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\AgentIde\OpenCodeServerConfigResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves remote local-bind tool config through the node wireguard address', function (): void {
    $node = Node::factory()->create([
        'name' => 'beast',
        'host' => 'beast.local',
        'wireguard_address' => '10.6.0.7',
    ]);
    $app = App::factory()->create([
        'name' => 'demo',
        'node_id' => $node->id,
    ]);

    NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'opencode-server',
        'expected_state' => 'installed',
        'config' => [
            'hostname' => '127.0.0.1',
            'port' => 4096,
        ],
        'credentials' => [
            'fields' => [
                'Username' => '(no auth)',
                'Password' => '(no auth)',
            ],
        ],
    ]);

    $config = app(OpenCodeServerConfigResolver::class)->resolve($app);

    expect($config->url)->toBe('http://10.6.0.7:4096')
        ->and($config->username)->toBeNull()
        ->and($config->password)->toBeNull();
});

it('prefers explicit opencode credentials url and auth fields', function (): void {
    $node = Node::factory()->create(['name' => 'beast']);
    $app = App::factory()->create([
        'name' => 'demo',
        'node_id' => $node->id,
    ]);

    NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'opencode-server',
        'expected_state' => 'installed',
        'config' => [
            'hostname' => '127.0.0.1',
            'port' => 4096,
        ],
        'credentials' => [
            'fields' => [
                'url' => 'https://opencode.beast.test/',
                'username' => 'orbit',
                'password' => 'secret',
            ],
        ],
    ]);

    $config = app(OpenCodeServerConfigResolver::class)->resolve($app);

    expect($config->url)->toBe('https://opencode.beast.test')
        ->and($config->username)->toBe('orbit')
        ->and($config->password)->toBe('secret');
});

it('resolves remote credential host and port through the node wireguard address', function (): void {
    $node = Node::factory()->create([
        'name' => 'beast',
        'host' => 'beast.local',
        'wireguard_address' => '10.6.0.7',
    ]);
    $app = App::factory()->create([
        'name' => 'demo',
        'node_id' => $node->id,
    ]);

    NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'opencode-server',
        'expected_state' => 'installed',
        'credentials' => [
            'fields' => [
                'Host' => '127.0.0.1',
                'Port' => '4100',
            ],
        ],
    ]);

    $config = app(OpenCodeServerConfigResolver::class)->resolve($app);

    expect($config->url)->toBe('http://10.6.0.7:4100');
});
