<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\RoleBaselines\VpnRoleBaseline;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('VpnRoleBaseline', function (): void {
    beforeEach(function (): void {
        config()->set('services.wg_easy.password', 'secret-password');
        config()->set('services.wg_easy.username', 'orbit-admin');

        $this->vpnDnsInstaller = new class extends VpnDnsSwarmInstaller
        {
            /** @var list<array{publicHost: string, username: string, password: string, wireguardCidr: string, wireguardPort: int, dnsIp: string}> */
            public array $invocations = [];

            public function __construct() {}

            public function install(
                string $publicHost,
                string $username,
                string $password,
                string $wireguardCidr = '10.6.0.0/24',
                int $wireguardPort = 51820,
                string $dnsIp = '10.6.0.1',
            ): void {
                $this->invocations[] = [
                    'publicHost' => $publicHost,
                    'username' => $username,
                    'password' => $password,
                    'wireguardCidr' => $wireguardCidr,
                    'wireguardPort' => $wireguardPort,
                    'dnsIp' => $dnsIp,
                ];
            }
        };

    });

    it('installs the vpn dns Swarm runtime when the vpn role has a public endpoint', function (): void {
        $node = Node::factory()->create();
        $assignment = NodeRoleAssignment::factory()->for($node)->create([
            'role' => 'vpn',
            'settings' => [
                'public_endpoint' => 'vpn.example.com',
                'wireguard_cidr' => '10.7.0.0/24',
                'wireguard_port' => 51830,
                'dns_ip' => '10.7.0.1',
            ],
        ]);

        $baseline = new VpnRoleBaseline($this->vpnDnsInstaller);

        $baseline->converge($node, $assignment);

        expect($this->vpnDnsInstaller->invocations)->toBe([
            [
                'publicHost' => 'vpn.example.com',
                'username' => 'orbit-admin',
                'password' => 'secret-password',
                'wireguardCidr' => '10.7.0.0/24',
                'wireguardPort' => 51830,
                'dnsIp' => '10.7.0.1',
            ],
        ]);
    });

    it('uses the default wg-easy username when it is not configured', function (): void {
        config()->set('services.wg_easy.username', null);

        $node = Node::factory()->create();
        $assignment = NodeRoleAssignment::factory()->for($node)->create([
            'role' => 'vpn',
            'settings' => [
                'public_endpoint' => 'vpn.example.com',
            ],
        ]);

        $baseline = new VpnRoleBaseline($this->vpnDnsInstaller);

        $baseline->converge($node, $assignment);

        expect($this->vpnDnsInstaller->invocations[0]['username'])->toBe('orbit')
            ->and($this->vpnDnsInstaller->invocations[0]['wireguardCidr'])->toBe('10.6.0.0/24')
            ->and($this->vpnDnsInstaller->invocations[0]['wireguardPort'])->toBe(51820)
            ->and($this->vpnDnsInstaller->invocations[0]['dnsIp'])->toBe('10.6.0.1');
    });

    it('does nothing when the vpn role has no public endpoint', function (): void {
        $node = Node::factory()->create();
        $assignment = NodeRoleAssignment::factory()->for($node)->create([
            'role' => 'vpn',
            'settings' => [
                'public_endpoint' => null,
            ],
        ]);

        $baseline = new VpnRoleBaseline($this->vpnDnsInstaller);

        $baseline->converge($node, $assignment);

        expect($this->vpnDnsInstaller->invocations)->toBe([]);
    });

    it('requires the wg-easy password when converging runtime', function (): void {
        config()->set('services.wg_easy.password', null);

        $node = Node::factory()->create();
        $assignment = NodeRoleAssignment::factory()->for($node)->create([
            'role' => 'vpn',
            'settings' => [
                'public_endpoint' => 'vpn.example.com',
            ],
        ]);

        $baseline = new VpnRoleBaseline($this->vpnDnsInstaller);

        expect(fn (): mixed => $baseline->converge($node, $assignment))
            ->toThrow(RuntimeException::class, 'WG_EASY_PASSWORD is required to converge the vpn role runtime.');
    });

    it('cannot be removed independently', function (): void {
        $node = Node::factory()->create();
        $assignment = NodeRoleAssignment::factory()->for($node)->create([
            'role' => 'vpn',
        ]);

        $baseline = new VpnRoleBaseline($this->vpnDnsInstaller);

        expect(fn (): mixed => $baseline->remove($node, $assignment, purgeData: false))
            ->toThrow(RuntimeException::class, 'The vpn role cannot be removed independently in this version.');
    });
});
