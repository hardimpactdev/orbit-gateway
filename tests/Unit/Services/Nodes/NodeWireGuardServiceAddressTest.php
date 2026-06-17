<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Nodes\NodeWireGuardServiceAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe(NodeWireGuardServiceAddress::class, function (): void {
    it('uses the dependency owner node WireGuard IP for remote service access', function (): void {
        $databaseNode = Node::factory()->create([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.7',
        ]);
        $appNode = Node::factory()->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.8',
        ]);

        $address = app(NodeWireGuardServiceAddress::class)->forServiceOn($databaseNode, $appNode, 'postgres');

        expect($address)->toBe('10.6.0.7');
    });

    it('uses the owner node WireGuard IP even for same-node service access', function (): void {
        $node = Node::factory()->create([
            'name' => 'combined-1',
            'wireguard_address' => '10.6.0.9',
        ]);

        $address = app(NodeWireGuardServiceAddress::class)->forServiceOn($node, $node, 'redis');

        expect($address)->toBe('10.6.0.9');
    });

    it('fails clearly when the owner node has no WireGuard IP', function (): void {
        $databaseNode = Node::factory()->create([
            'name' => 'database-1',
            'wireguard_address' => null,
        ]);
        $appNode = Node::factory()->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.8',
        ]);

        $exception = null;

        try {
            app(NodeWireGuardServiceAddress::class)->forServiceOn($databaseNode, $appNode, 'postgres');
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        expect($exception)->toBeInstanceOf(RuntimeException::class)
            ->and($exception?->getMessage())->toContain('database-1')
            ->and($exception?->getMessage())->toContain('postgres');
    });
});
