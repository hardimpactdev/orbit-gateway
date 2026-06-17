<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('has node host-key pinning columns', function (): void {
    expect(Schema::hasColumns('nodes', [
        'host_key_type',
        'host_key_fingerprint',
        'host_key_public',
        'host_key_pinned_at',
        'host_key_pin_mode',
    ]))->toBeTrue();
});

it('has firewall rule ownership and network-scope columns', function (): void {
    expect(Schema::hasColumns('firewall_rules', [
        'address_family',
        'interface',
        'owner',
        'protected',
    ]))->toBeTrue();
});

it('documents provisioning as a transient node status', function (): void {
    expect(NodeStatus::Provisioning->value)->toBe('provisioning')
        ->and(NodeStatus::Active->value)->toBe('active');
});
