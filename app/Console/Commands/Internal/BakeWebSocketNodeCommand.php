<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Data\Security\PinnedHostKey;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Services\Nodes\NodeRegistryWriter;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Security\SshHostKeyPinner;
use App\Services\WebSockets\WebSocketRoleBaselineTiming;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('orbit:internal:bake-websocket-node
    {name : Node name receiving the WebSocket role}
    {--host= : Node host address}
    {--host-key-host= : Host/IP to scan for the SSH host key when different from --host}
    {--wireguard-address= : Node WireGuard address}
    {--gateway-endpoint= : Gateway endpoint address}
    {--user=orbit : Runtime user}
    {--redis-node= : Existing database node name for Redis}
    {--converge-runtime : Converge the WebSocket Reverb runtime baseline after registry bake}')]
#[Description('Bake websocket role registry state for prepared E2E topology images')]
class BakeWebSocketNodeCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(
        NodeRegistryWriter $registryWriter,
        NodeRoleBaselineConverger $converger,
        WebSocketRoleBaselineTiming $roleBaselineTiming,
    ): int {
        $name = $this->stringArgument('name');
        $host = $this->stringOption('host');
        $hostKeyHost = $this->stringOption('host-key-host');
        $wireguardAddress = $this->stringOption('wireguard-address');
        $gatewayEndpoint = $this->stringOption('gateway-endpoint');
        $user = $this->stringOption('user') ?? 'orbit';
        $redisNodeName = $this->stringOption('redis-node');

        if ($name === null || $host === null || $wireguardAddress === null || $redisNodeName === null) {
            throw new RuntimeException('Name, host, wireguard-address, and redis-node are required.');
        }

        $redisNode = $this->measureBakeStep(
            'redis-node',
            fn (): Node => $this->activeRedisNode($redisNodeName),
        );
        $hostKey = $this->measureBakeStep(
            'host-key',
            fn (): PinnedHostKey => app(SshHostKeyPinner::class)->pin($hostKeyHost ?? $host),
        );

        $node = $this->measureBakeStep(
            'registry',
            fn (): Node => $this->writeWebSocketRoleNode(
                registryWriter: $registryWriter,
                name: $name,
                host: $host,
                wireguardAddress: $wireguardAddress,
                gatewayEndpoint: $gatewayEndpoint,
                user: $user,
                hostKey: $hostKey,
            ),
        );

        $assignment = $this->measureBakeStep(
            'role-assignment',
            fn (): NodeRoleAssignment => NodeRoleAssignment::query()->updateOrCreate(
                [
                    'node_id' => $node->id,
                    'role' => NodeRoleName::WebSocket->value,
                ],
                [
                    'status' => NodeRoleStatus::Active->value,
                    'settings' => ['redis_node_id' => $redisNode->id],
                    'last_error' => null,
                    'converged_at' => now(),
                ],
            ),
        );

        if ($this->option('converge-runtime') === true) {
            $roleBaselineTiming->reset();

            $this->measureBakeStep(
                'runtime-converge',
                fn () => $this->convergeRuntime($converger, $node, $assignment),
            );

            foreach ($roleBaselineTiming->records() as $record) {
                $this->line("__orbit_bake_timing websocket runtime-{$record['step']} {$record['milliseconds']}");
            }
        }

        return self::SUCCESS;
    }

    private function activeRedisNode(string $name): Node
    {
        $node = Node::query()
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn ($query) => $query
                ->where('role', NodeRoleName::Database->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->first();

        if (! $node instanceof Node) {
            throw new RuntimeException("Active Redis node [{$name}] was not found.");
        }

        $hasRedis = Process::query()
            ->ownedBy($node)
            ->where('runtime_config->definition', 'redis')
            ->exists();

        if (! $hasRedis) {
            throw new RuntimeException("Active Redis node [{$name}] was not found.");
        }

        return $node;
    }

    private function writeWebSocketRoleNode(
        NodeRegistryWriter $registryWriter,
        string $name,
        string $host,
        string $wireguardAddress,
        ?string $gatewayEndpoint,
        string $user,
        PinnedHostKey $hostKey,
    ): Node {
        $existing = Node::query()
            ->where('name', $name)
            ->first();

        if (! $existing instanceof Node) {
            return $registryWriter->writeNodeIdentity(
                name: $name,
                tld: null,
                platform: 'ubuntu',
                host: $host,
                wireguardAddress: $wireguardAddress,
                gatewayEndpoint: $gatewayEndpoint,
                user: $user,
                orbitPath: "/home/{$user}/orbit",
                hostKey: $hostKey,
            );
        }

        $existing->forceFill([
            'platform' => 'ubuntu',
            'host' => $host,
            'wireguard_address' => $wireguardAddress,
            'gateway_endpoint' => $gatewayEndpoint,
            'user' => $user,
            'orbit_path' => "/home/{$user}/orbit",
            'status' => NodeStatus::Active,
            'host_key_type' => $hostKey->type,
            'host_key_fingerprint' => $hostKey->fingerprint,
            'host_key_public' => $hostKey->publicKey,
            'host_key_pin_mode' => $hostKey->pinMode,
            'host_key_pinned_at' => now(),
        ])->save();

        return $existing->refresh();
    }

    private function convergeRuntime(NodeRoleBaselineConverger $converger, Node $node, NodeRoleAssignment $assignment): void
    {
        try {
            $converger->converge($node, $assignment);

            $assignment->forceFill([
                'status' => NodeRoleStatus::Active->value,
                'last_error' => null,
                'converged_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            $assignment->forceFill([
                'status' => NodeRoleStatus::Error->value,
                'last_error' => $exception->getMessage(),
                'converged_at' => null,
            ])->save();

            throw $exception;
        }
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

    private function measureBakeStep(string $step, callable $callback): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $callback();
        } finally {
            $milliseconds = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            $this->line("__orbit_bake_timing websocket {$step} {$milliseconds}");
        }
    }
}
