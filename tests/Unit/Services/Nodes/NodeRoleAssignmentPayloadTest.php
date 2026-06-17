<?php

declare(strict_types=1);

use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use Tests\TestCase;

uses(TestCase::class);

it('normalizes empty role settings to a JSON object payload', function (): void {
    $payload = NodeRoleAssignmentPayload::fromArray([
        'status' => 'active',
        'settings' => [],
    ]);

    expect($payload['settings'])->toBeInstanceOf(stdClass::class);
});

it('preserves keyed role settings payloads', function (): void {
    $settings = ['tld' => 'test'];

    $payload = NodeRoleAssignmentPayload::fromArray([
        'status' => 'active',
        'settings' => $settings,
    ]);

    expect($payload['settings'])->toBe($settings);
});
