<?php

declare(strict_types=1);

use App\Data\Security\PinnedHostKey;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('orbit:internal:pin-node-host-keys', function (): void {
    beforeEach(function (): void {
        $this->hostKeyPinner = new class
        {
            /** @var list<array{host: string, expected: ?string}> */
            public array $calls = [];

            /** @var list<string> */
            public array $failHosts = [];

            public function pin(string $host, ?string $expectedFingerprint = null): PinnedHostKey
            {
                $this->calls[] = ['host' => $host, 'expected' => $expectedFingerprint];

                if (in_array($host, $this->failHosts, true)) {
                    throw new RuntimeException("Could not scan {$host}.");
                }

                return new PinnedHostKey(
                    host: $host,
                    type: 'ssh-ed25519',
                    publicKey: 'AAAAC3NzaC1lZDI1NTE5AAAAIPinNodeHostKeysCommand',
                    fingerprint: 'SHA256:'.str_replace('.', '-', $host),
                    pinMode: 'tofu',
                );
            }

            public function persist(Node $node, PinnedHostKey $key): void
            {
                $node->forceFill([
                    'host_key_type' => $key->type,
                    'host_key_public' => $key->publicKey,
                    'host_key_fingerprint' => $key->fingerprint,
                    'host_key_pin_mode' => $key->pinMode,
                    'host_key_pinned_at' => now(),
                ])->save();
            }
        };

        app()->instance(SshHostKeyPinner::class, $this->hostKeyPinner);
    });

    it('pins hosted app and agent nodes while skipping topology peer identities', function (): void {
        $agent = Node::factory()->operator()->create([
            'name' => 'agent-1',
            'host' => '10.6.0.6',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $agent->id,
            'role' => NodeRoleName::Agent->value,
            'status' => NodeRoleStatus::Active->value,
        ]);

        $app = Node::factory()->appDev()->create([
            'name' => 'app-dev-1',
            'host' => '10.6.0.4',
        ]);

        $gateway = Node::factory()->create([
            'name' => 'gateway',
            'host' => '10.6.0.2',
        ]);
        $operator = Node::factory()->operator()->create([
            'name' => 'operator',
            'host' => '10.6.0.3',
        ]);

        $this->artisan('orbit:internal:pin-node-host-keys', ['--json' => true])
            ->assertSuccessful();

        expect(array_column($this->hostKeyPinner->calls, 'host'))->toBe([
            '10.6.0.6',
            '10.6.0.4',
        ])
            ->and($agent->refresh()->host_key_fingerprint)->toBe('SHA256:10-6-0-6')
            ->and($app->refresh()->host_key_fingerprint)->toBe('SHA256:10-6-0-4')
            ->and($gateway->refresh()->host_key_fingerprint)->toBeNull()
            ->and($operator->refresh()->host_key_fingerprint)->toBeNull();
    });

    it('fails when any hosted node cannot be pinned', function (): void {
        $this->hostKeyPinner->failHosts = ['10.6.0.4'];

        Node::factory()->appDev()->create([
            'name' => 'app-dev-1',
            'host' => '10.6.0.4',
        ]);

        $this->artisan('orbit:internal:pin-node-host-keys', ['--json' => true])
            ->assertExitCode(1);

        expect(Node::query()->where('name', 'app-dev-1')->firstOrFail()->host_key_fingerprint)->toBeNull();
    });
});
