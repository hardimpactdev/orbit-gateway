<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('registers a node through the hidden internal bootstrap command', function (): void {
    $this->artisan('orbit:internal:node-register', [
        'name' => 'gateway',
        '--host' => 'gateway',
        '--user' => 'gateway',
        '--orbit-path' => '/home/gateway/orbit',
    ])
        ->expectsOutputToContain('Registered node gateway.')
        ->assertSuccessful();

    $node = DB::table('nodes')->where('name', 'gateway')->first();

    expect($node)->not->toBeNull()
        ->and((array) $node)->not->toHaveKeys(['role', 'environment'])
        ->and($node->host)->toBe('gateway')
        ->and($node->user)->toBe('gateway')
        ->and($node->orbit_path)->toBe('/home/gateway/orbit');
});
