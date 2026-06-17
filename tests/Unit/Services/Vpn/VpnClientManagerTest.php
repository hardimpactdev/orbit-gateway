<?php

declare(strict_types=1);

use App\Data\Vpn\VpnBackendClient;
use App\Models\Node;
use App\Services\Vpn\ArrayVpnBackend;
use App\Services\Vpn\VpnClientManager;
use App\Services\Vpn\VpnFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('classifies active node peers by name and address', function (): void {
    Node::factory()->create([
        'name' => 'app-1',
        'wireguard_address' => '10.6.0.8',
        'status' => 'active',
    ]);

    $manager = new VpnClientManager(new ArrayVpnBackend([
        new VpnBackendClient('client-1', 'laptop', '10.6.0.7', true, null),
        new VpnBackendClient('client-2', 'app-1', '10.6.0.9', true, null),
        new VpnBackendClient('client-3', 'stale-name', '10.6.0.8', true, null),
    ]));

    $clients = $manager->list();

    expect($clients)->toHaveCount(3)
        ->and($clients[0]->kind)->toBe('admin')
        ->and($clients[1]->kind)->toBe('node')
        ->and($clients[2]->kind)->toBe('node');
});

it('refuses to create clients with active node names', function (): void {
    Node::factory()->create([
        'name' => 'app-1',
        'status' => 'active',
    ]);

    $manager = new VpnClientManager(new ArrayVpnBackend);
    $result = $manager->create('app-1', includeConfig: false);

    expect($result)->toBeInstanceOf(VpnFailure::class)
        ->and($result->code)->toBe('validation_failed')
        ->and($result->meta['reason'])->toBe('node_name_reserved');
});

it('protects active node peers from write commands', function (): void {
    Node::factory()->create([
        'name' => 'app-1',
        'wireguard_address' => '10.6.0.8',
        'status' => 'active',
    ]);

    $manager = new VpnClientManager(new ArrayVpnBackend([
        new VpnBackendClient('client-1', 'app-1', '10.6.0.8', true, null),
    ]));

    $result = $manager->disable('app-1');

    expect($result)->toBeInstanceOf(VpnFailure::class)
        ->and($result->code)->toBe('validation_failed')
        ->and($result->meta['reason'])->toBe('node_peer_protected')
        ->and($result->meta['next_command'])->toBe('node:remove app-1');
});

it('returns idempotent enable and disable results', function (): void {
    $manager = new VpnClientManager(new ArrayVpnBackend([
        new VpnBackendClient('client-1', 'laptop', '10.6.0.7', true, null),
    ]));

    $enabled = $manager->enable('laptop');
    $disabled = $manager->disable('laptop');

    expect($enabled->alreadyInDesiredState)->toBeTrue()
        ->and($enabled->client->enabled)->toBeTrue()
        ->and($disabled->alreadyInDesiredState)->toBeFalse()
        ->and($disabled->client->enabled)->toBeFalse();
});

it('does not mutate existing backend client values when toggling the array backend', function (): void {
    $original = new VpnBackendClient('client-1', 'laptop', '10.6.0.7', true, null);
    $backend = new ArrayVpnBackend([$original]);

    $disabled = $backend->disableClient('laptop');

    expect($original->enabled)->toBeTrue()
        ->and($disabled)->not->toBe($original)
        ->and($disabled->enabled)->toBeFalse()
        ->and($backend->clients()[0])->toBe($disabled);
});
