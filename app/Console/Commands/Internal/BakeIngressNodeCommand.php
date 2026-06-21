<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\NodeRegistryWriter;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('orbit:internal:bake-ingress-node
    {name : Ingress node name}
    {--host= : Ingress node host address}
    {--host-key-host= : Host/IP to scan for the SSH host key when different from --host}
    {--wireguard-address= : Ingress node WireGuard address}
    {--gateway-endpoint= : Gateway endpoint address}
    {--user=orbit : Runtime user}')]
#[Description('Bake an ingress node registry row for prepared E2E topology images')]
class BakeIngressNodeCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(NodeRegistryWriter $registryWriter): int
    {
        $name = $this->stringArgument('name');
        $host = $this->stringOption('host');
        $hostKeyHost = $this->stringOption('host-key-host');
        $wireguardAddress = $this->stringOption('wireguard-address');
        $gatewayEndpoint = $this->stringOption('gateway-endpoint');
        $user = $this->stringOption('user') ?? 'orbit';

        if ($name === null || $host === null || $wireguardAddress === null) {
            throw new RuntimeException('Name, host, and wireguard-address are required.');
        }

        $hostKey = app(SshHostKeyPinner::class)->pin($hostKeyHost ?? $host);

        $node = $registryWriter->writeNodeIdentity(
            name: $name,
            tld: null,
            platform: 'ubuntu',
            host: $host,
            wireguardAddress: $wireguardAddress,
            gatewayEndpoint: $gatewayEndpoint,
            user: $user,
            orbitPath: "/home/{$user}/orbit",
            status: NodeStatus::Active,
            hostKey: $hostKey,
        );

        NodeRoleAssignment::query()->updateOrCreate(
            [
                'node_id' => $node->id,
                'role' => NodeRoleName::Ingress->value,
            ],
            [
                'status' => NodeRoleStatus::Active->value,
                'settings' => [],
                'last_error' => null,
                'converged_at' => now(),
            ],
        );

        return self::SUCCESS;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
