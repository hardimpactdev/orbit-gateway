<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Data\Security\PinnedHostKey;
use App\Enums\Nodes\NodeConvergenceContext;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\NodeConverger;
use App\Services\Nodes\NodeRegistryWriter;
use App\Services\Security\SshHostKeyPinner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('orbit:internal:bake-app-node
    {name : App node name}
    {--role=app-dev : App node role: app-dev or app-prod}
    {--host= : App-node host address}
    {--host-key-host= : Host/IP to scan for the SSH host key when different from --host}
    {--wireguard-address= : App-node WireGuard address}
    {--gateway-endpoint= : Gateway endpoint address}
    {--user=orbit : Runtime user}
    {--tld= : Development app-node TLD}
    {--ingress-node= : Existing ingress node name for production placement}')]
#[Description('Bake an app-node registry row for prepared E2E topology images')]
class BakeAppNodeCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(NodeRegistryWriter $registryWriter, NodeConverger $nodeConverger): int
    {
        $name = $this->stringArgument('name');
        $role = $this->stringOption('role') ?? NodeRoleName::AppDevelopment->value;
        $host = $this->stringOption('host');
        $hostKeyHost = $this->stringOption('host-key-host');
        $wireguardAddress = $this->stringOption('wireguard-address');
        $gatewayEndpoint = $this->stringOption('gateway-endpoint');
        $user = $this->stringOption('user') ?? 'orbit';
        $tld = $this->stringOption('tld');
        $ingressNode = $this->stringOption('ingress-node');

        if ($name === null || $host === null || $wireguardAddress === null) {
            throw new RuntimeException('Name, host, and wireguard-address are required.');
        }

        if (! in_array($role, [NodeRoleName::AppDevelopment->value, NodeRoleName::AppProduction->value], true)) {
            throw new RuntimeException('Only app-dev and app-prod nodes can be baked with this command.');
        }

        $timingRole = $this->timingRole($role);

        $hostKey = $this->measureBakeStep(
            $timingRole,
            'host-key',
            fn (): PinnedHostKey => app(SshHostKeyPinner::class)->pin($hostKeyHost ?? $host),
        );

        $node = $this->measureBakeStep(
            $timingRole,
            'registry',
            fn (): Node => $registryWriter->writeAppNode(
                name: $name,
                tld: $tld,
                host: $host,
                wireguardAddress: $wireguardAddress,
                gatewayEndpoint: $gatewayEndpoint,
                sshUser: $user,
                user: $user,
                status: NodeStatus::Active,
                hostKey: $hostKey,
            ),
        );

        $this->measureBakeStep(
            $timingRole,
            'role-assignment',
            fn () => $this->upsertRoleAssignment($node->id, $role, $tld, $ingressNode),
        );

        $this->measureBakeStep(
            $timingRole,
            'setup-converge',
            fn () => $this->setupDevelopmentNode($nodeConverger, $node, $role, $timingRole),
        );

        return self::SUCCESS;
    }

    private function setupDevelopmentNode(NodeConverger $nodeConverger, Node $node, string $role, string $timingRole): void
    {
        if ($role !== NodeRoleName::AppDevelopment->value) {
            return;
        }

        $freshNode = $node->fresh();
        $node = $freshNode instanceof Node ? $freshNode : $node;

        $nodeResult = $this->measureBakeStep(
            $timingRole,
            'setup-node',
            fn () => $nodeConverger->converge(
                node: $node,
                context: NodeConvergenceContext::Setup,
                families: ['node'],
            ),
        );

        if (! $nodeResult->successful()) {
            throw new RuntimeException('Could not converge baked app-dev node baseline: '.json_encode($nodeResult->toArray(), JSON_THROW_ON_ERROR));
        }

        $freshNode = $node->fresh();
        $node = $freshNode instanceof Node ? $freshNode : $node;

        $toolResult = $this->measureBakeStep(
            $timingRole,
            'setup-tool',
            fn () => $nodeConverger->converge(
                node: $node,
                context: NodeConvergenceContext::Setup,
                families: ['tool'],
            ),
        );

        if (! $toolResult->successful()) {
            throw new RuntimeException('Could not converge baked app-dev node tools: '.json_encode($toolResult->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    private function upsertRoleAssignment(int $nodeId, string $role, ?string $tld, ?string $ingressNode): void
    {
        $settings = $tld !== null ? ['tld' => $tld] : [];

        if ($ingressNode !== null) {
            if ($role !== NodeRoleName::AppProduction->value) {
                throw new RuntimeException('Only production app nodes can reference ingress.');
            }

            $ingressNodeId = Node::query()
                ->where('name', $ingressNode)
                ->whereHas('roleAssignments', fn ($query) => $query
                    ->where('role', NodeRoleName::Ingress->value)
                    ->where('status', NodeRoleStatus::Active->value))
                ->value('id')
                ?? throw new RuntimeException("Active ingress node [{$ingressNode}] was not found.");

            $settings['ingress_node_id'] = $ingressNodeId;

            if ($ingressNodeId !== $nodeId) {
                NodeRoleAssignment::query()
                    ->where('node_id', $nodeId)
                    ->where('role', NodeRoleName::Ingress->value)
                    ->delete();
            }
        }

        NodeRoleAssignment::query()->updateOrCreate(
            [
                'node_id' => $nodeId,
                'role' => $role,
            ],
            [
                'status' => NodeRoleStatus::Active->value,
                'settings' => $settings,
                'last_error' => null,
                'converged_at' => now(),
            ],
        );
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

    private function measureBakeStep(string $role, string $step, callable $callback): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $callback();
        } finally {
            $milliseconds = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            $this->line("__orbit_bake_timing {$role} {$step} {$milliseconds}");
        }
    }

    private function timingRole(string $role): string
    {
        return match ($role) {
            NodeRoleName::AppDevelopment->value => 'dev',
            NodeRoleName::AppProduction->value => 'prod',
            default => $role,
        };
    }
}
