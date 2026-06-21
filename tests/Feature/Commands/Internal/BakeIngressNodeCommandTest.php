<?php

declare(strict_types=1);

use App\Data\Security\PinnedHostKey;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('orbit:internal:bake-ingress-node', function (): void {
    beforeEach(function (): void {
        $this->hostKeyPinner = new class
        {
            /** @var list<array{host: string, expected: ?string}> */
            public array $calls = [];

            public function pin(string $host, ?string $expectedFingerprint = null): PinnedHostKey
            {
                $this->calls[] = ['host' => $host, 'expected' => $expectedFingerprint];

                return new PinnedHostKey(
                    host: $host,
                    type: 'ssh-ed25519',
                    publicKey: 'AAAAC3NzaC1lZDI1NTE5AAAAIBakeIngressNodeHostKey',
                    fingerprint: 'SHA256:bake-ingress-node-host-key',
                    pinMode: 'tofu',
                );
            }
        };

        app()->instance(SshHostKeyPinner::class, $this->hostKeyPinner);
    });

    it('writes an ingress node row with an active role assignment', function (): void {
        $this->artisan('orbit:internal:bake-ingress-node', [
            'name' => 'edge-1',
            '--host' => 'ingress',
            '--host-key-host' => '10.6.0.7',
            '--wireguard-address' => '10.6.0.7',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
        ])->assertSuccessful();

        $node = Node::query()->where('name', 'edge-1')->firstOrFail();
        $assignment = NodeRoleAssignment::query()
            ->where('node_id', $node->id)
            ->where('role', NodeRoleName::Ingress->value)
            ->first();

        expect($node->host)->toBe('ingress')
            ->and($node->wireguard_address)->toBe('10.6.0.7')
            ->and($node->gateway_endpoint)->toBe('10.6.0.2')
            ->and($node->user)->toBe('orbit')
            ->and($node->orbit_path)->toBe('/home/orbit/orbit')
            ->and($node->status)->toBe(NodeStatus::Active)
            ->and($node->host_key_type)->toBe('ssh-ed25519')
            ->and($node->host_key_public)->toBe('AAAAC3NzaC1lZDI1NTE5AAAAIBakeIngressNodeHostKey')
            ->and($node->host_key_fingerprint)->toBe('SHA256:bake-ingress-node-host-key')
            ->and($node->host_key_pin_mode)->toBe('tofu')
            ->and($node->host_key_pinned_at)->not->toBeNull()
            ->and($this->hostKeyPinner->calls)->toBe([
                ['host' => '10.6.0.7', 'expected' => null],
            ])
            ->and($assignment)->not->toBeNull()
            ->and($assignment?->status)->toBe(NodeRoleStatus::Active)
            ->and($assignment?->settings)->toBe([]);
    });
});
