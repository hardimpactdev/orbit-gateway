<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Vpn\ActiveVpnNodeUnavailable;
use App\Services\Vpn\VpnNodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('VpnNodeResolver', function (): void {
    it('returns the first active vpn role node ordered by id', function (): void {
        $first = Node::factory()->create([
            'name' => 'vpn-1',
            'status' => 'active',
        ]);
        $second = Node::factory()->create([
            'name' => 'vpn-2',
            'status' => 'active',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $second->id,
            'role' => 'vpn',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $first->id,
            'role' => 'vpn',
            'status' => 'active',
        ]);

        $resolved = app(VpnNodeResolver::class)->activeVpnNode();

        expect($resolved->is($first))->toBeTrue();
    });

    it('throws when no active vpn role node exists', function (): void {
        Node::factory()->create([
            'name' => 'gateway-1',
            'status' => 'active',
        ]);

        expect(fn () => app(VpnNodeResolver::class)->activeVpnNode())
            ->toThrow(ActiveVpnNodeUnavailable::class, 'No active vpn role node is available.');
    });
});
