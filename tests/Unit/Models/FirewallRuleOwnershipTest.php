<?php

declare(strict_types=1);

use App\Models\FirewallRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('keeps user-owned firewall rules unprotected', function (): void {
    $rule = FirewallRule::factory()->create([
        'owner' => 'user',
        'protected' => true,
    ]);

    expect($rule->refresh()->protected)->toBeFalse();
});

it('defaults missing firewall rule owners to user-owned and unprotected', function (): void {
    $rule = FirewallRule::factory()->create([
        'owner' => null,
        'protected' => true,
    ]);

    expect($rule->refresh()->owner)->toBe('user')
        ->and($rule->protected)->toBeFalse();
});

it('marks non-user firewall rules as protected', function (): void {
    $rule = FirewallRule::factory()->create([
        'owner' => 'node-security',
        'protected' => false,
    ]);

    expect($rule->refresh()->protected)->toBeTrue();
});
