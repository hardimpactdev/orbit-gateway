<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Data\Nodes\RoleSettings\VpnRoleSettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use RuntimeException;

class VpnRoleBaseline implements RoleBaseline
{
    public function __construct(
        private readonly ?VpnDnsSwarmInstaller $vpnDnsSwarmInstaller = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        $settings = VpnRoleSettings::fromArray($assignment->settings ?? []);

        if ($settings->publicEndpoint === null) {
            return;
        }

        $password = config('services.wg_easy.password');

        if (! is_string($password) || trim($password) === '') {
            throw new RuntimeException('WG_EASY_PASSWORD is required to converge the vpn role runtime.');
        }

        $username = config('services.wg_easy.username', 'orbit');
        $username = is_string($username) && trim($username) !== '' ? trim($username) : 'orbit';

        $this->vpnDnsSwarmInstaller()->install(
            publicHost: $settings->publicEndpoint,
            username: $username,
            password: $password,
            wireguardCidr: $settings->wireguardCidr,
            wireguardPort: $settings->wireguardPort,
            dnsIp: $settings->dnsIp,
        );
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        throw new RuntimeException('The vpn role cannot be removed independently in this version.');
    }

    private function vpnDnsSwarmInstaller(): VpnDnsSwarmInstaller
    {
        return $this->vpnDnsSwarmInstaller ?? app(VpnDnsSwarmInstaller::class);
    }
}
