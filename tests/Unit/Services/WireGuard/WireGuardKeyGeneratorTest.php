<?php

declare(strict_types=1);

namespace Tests\Unit\Services\WireGuard;

use App\Models\Node;
use App\Models\WireGuardPeer;
use App\Services\WireGuard\WireGuardKeyGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->generator = new WireGuardKeyGenerator;
});

describe('key generation', function (): void {
    it('generates a valid key pair without requiring a wg binary on the runner', function (): void {
        Process::preventStrayProcesses();

        $result = $this->generator->generateKeyPair();
        $privateKey = base64_decode($result['private_key'], strict: true);
        $publicKey = base64_decode($result['public_key'], strict: true);

        expect($privateKey)->toBeString()
            ->and(strlen($privateKey))->toBe(32)
            ->and($publicKey)->toBeString()
            ->and(strlen($publicKey))->toBe(32)
            ->and($result['public_key'])->toBe(base64_encode(sodium_crypto_scalarmult_base($privateKey)));
    });
});

describe('peer persistence', function (): void {
    it('persists a wireguard peer with a node', function (): void {
        $node = Node::factory()->create();

        $peer = WireGuardPeer::create([
            'node_id' => $node->id,
            'public_key' => 'abc123',
            'private_key' => 'def456',
            'pre_shared_key' => 'ghi789',
            'allowed_ips' => '10.0.0.2/32',
        ]);

        expect($peer->fresh())->toBeInstanceOf(WireGuardPeer::class);
        expect($peer->node->id)->toBe($node->id);
        expect($peer->public_key)->toBe('abc123');
        expect($peer->private_key)->toBe('def456');
        expect($peer->pre_shared_key)->toBe('ghi789');
        expect($peer->allowed_ips)->toBe('10.0.0.2/32');
    });

    it('enforces unique node constraint', function (): void {
        $node = Node::factory()->create();

        WireGuardPeer::factory()->create(['node_id' => $node->id]);

        expect(fn () => WireGuardPeer::factory()->create(['node_id' => $node->id]))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('allows nullable pre_shared_key and allowed_ips', function (): void {
        $node = Node::factory()->create();

        $peer = WireGuardPeer::create([
            'node_id' => $node->id,
            'public_key' => 'pubkey',
            'private_key' => 'privkey',
        ]);

        expect($peer->pre_shared_key)->toBeNull();
        expect($peer->allowed_ips)->toBeNull();
    });
});
