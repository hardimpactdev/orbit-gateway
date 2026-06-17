<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\RoleBaselines\ManagesNodeToolBaseline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class NodeRoleAssignments
{
    /**
     * @return list<string>
     */
    public function appHostRoles(): array
    {
        return [
            NodeRoleName::AppDevelopment->value,
            NodeRoleName::AppProduction->value,
        ];
    }

    /**
     * @return list<string>
     */
    public function toolHostRoles(): array
    {
        return [
            ...$this->appHostRoles(),
            NodeRoleName::Database->value,
            NodeRoleName::Agent->value,
            NodeRoleName::S3->value,
            NodeRoleName::Metrics->value,
            NodeRoleName::Analytics->value,
        ];
    }

    /**
     * Metrics node-exporter is baseline-owned on every active role-bearing node
     * that metrics convergence scrapes, not only generic tool-host roles.
     *
     * @return list<string>
     */
    public function metricsExporterHostRoles(): array
    {
        return array_map(
            static fn (NodeRoleName $role): string => $role->value,
            NodeRoleName::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public function gatewayOrAppHostRoles(): array
    {
        return [
            NodeRoleName::Gateway->value,
            ...$this->appHostRoles(),
        ];
    }

    public function nodeHasActiveRole(Node $node, string $role): bool
    {
        return $this->activeAssignment($node, $role) instanceof NodeRoleAssignment;
    }

    public function activeAssignment(Node $node, string $role): ?NodeRoleAssignment
    {
        if ($node->relationLoaded('roleAssignments')) {
            return $node->roleAssignments
                ->first(fn (NodeRoleAssignment $assignment): bool => $assignment->role === $role
                    && $assignment->status === NodeRoleStatus::Active);
        }

        return $node->roleAssignments()
            ->where('role', $role)
            ->where('status', NodeRoleStatus::Active->value)
            ->first();
    }

    public function nodeHasActiveGatewayRole(Node $node): bool
    {
        return $this->nodeHasActiveRole($node, NodeRoleName::Gateway->value);
    }

    public function nodeHasActiveVpnRole(Node $node): bool
    {
        return $this->nodeHasActiveRole($node, NodeRoleName::Vpn->value);
    }

    public function nodeHasActiveRouterRole(Node $node): bool
    {
        return $this->nodeHasActiveRole($node, NodeRoleName::Router->value);
    }

    public function nodeHasActiveIngressRole(Node $node): bool
    {
        return $this->nodeHasActiveRole($node, NodeRoleName::Ingress->value);
    }

    public function nodeHasActiveAgentRole(Node $node): bool
    {
        return $this->nodeHasActiveRole($node, NodeRoleName::Agent->value);
    }

    /**
     * @return Builder<Node>
     */
    public function activeGatewayNodeQuery(): Builder
    {
        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->activeNodeIdsForRole(NodeRoleName::Gateway->value));
    }

    /**
     * @return Builder<Node>
     */
    public function activeVpnNodeQuery(): Builder
    {
        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->activeNodeIdsForRole(NodeRoleName::Vpn->value));
    }

    /**
     * @return Builder<Node>
     */
    public function activeRouterNodeQuery(): Builder
    {
        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->activeNodeIdsForRole(NodeRoleName::Router->value));
    }

    /**
     * @return Builder<Node>
     */
    public function activeIngressNodeQuery(): Builder
    {
        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->activeIngressNodeIds());
    }

    public function nodeIsGateway(Node $node): bool
    {
        return $node->status === NodeStatus::Active
            && $this->nodeHasActiveGatewayRole($node);
    }

    public function nodeCanServeIngress(Node $node): bool
    {
        return $node->status === NodeStatus::Active
            && $this->nodeHasActiveIngressRole($node);
    }

    public function nodeCanServeRouter(Node $node): bool
    {
        return $node->status === NodeStatus::Active
            && $this->nodeHasActiveRouterRole($node);
    }

    public function nodeHasActiveAppHostRole(Node $node): bool
    {
        return $this->nodeHasAnyActiveRole($node, $this->appHostRoles());
    }

    public function nodeHasActiveToolHostRole(Node $node): bool
    {
        return $this->nodeHasAnyActiveRole($node, $this->toolHostRoles());
    }

    public function activeAppHostEnvironment(Node $node): ?string
    {
        if ($this->nodeHasActiveRole($node, NodeRoleName::AppDevelopment->value)) {
            return 'development';
        }

        if ($this->nodeHasActiveRole($node, NodeRoleName::AppProduction->value)) {
            return 'production';
        }

        return null;
    }

    public function assignmentRoleLabel(Node $node): string
    {
        foreach ([
            NodeRoleName::Gateway,
            NodeRoleName::AppDevelopment,
            NodeRoleName::AppProduction,
            NodeRoleName::Database,
            NodeRoleName::Agent,
            NodeRoleName::Ingress,
            NodeRoleName::Vpn,
            NodeRoleName::Router,
            NodeRoleName::Metrics,
            NodeRoleName::Analytics,
        ] as $role) {
            if ($this->nodeHasActiveRole($node, $role->value)) {
                return $role->value;
            }
        }

        return 'operator';
    }

    public function nodeCanServeGatewayOrAppHostWorkloads(Node $node): bool
    {
        return $this->nodeIsGateway($node)
            || $this->nodeHasActiveAppHostRole($node);
    }

    /**
     * Roles whose nodes may own firewall rules. Any active role assignment makes
     * a managed node an eligible firewall target (2026-06-10 product decision).
     * Single source of truth shared by firewall rule creation (intent) and the
     * firewall doctor probe so the two cannot drift apart.
     *
     * @return list<string>
     */
    public function firewallEligibleRoles(): array
    {
        return array_map(
            static fn (NodeRoleName $role): string => $role->value,
            NodeRoleName::cases(),
        );
    }

    public function nodeCanOwnFirewallRules(Node $node): bool
    {
        return $this->nodeHasAnyActiveRole($node, $this->firewallEligibleRoles());
    }

    /**
     * Roles whose baseline provisions an orbit-caddy container on the node.
     * Mirrors {@see ManagesNodeToolBaseline::defaultOrbitCaddyContainer()}:
     * gateway, app-host, and router nodes get a private/default spec; ingress
     * nodes get the public-ingress spec.
     */
    public function nodeHostsOrbitCaddy(Node $node): bool
    {
        return $this->nodeCanServeGatewayOrAppHostWorkloads($node)
            || $this->nodeCanServeRouter($node)
            || $this->nodeCanServeIngress($node);
    }

    public function nodeCanHostManagedTools(Node $node): bool
    {
        return $this->nodeIsGateway($node)
            || $this->nodeHasActiveToolHostRole($node);
    }

    public function nodeCanHostMetricsExporter(Node $node): bool
    {
        return $node->status === NodeStatus::Active
            && $this->nodeHasAnyActiveRole($node, $this->metricsExporterHostRoles());
    }

    /**
     * @param  list<string>  $roles
     */
    public function nodeHasAnyActiveRole(Node $node, array $roles): bool
    {
        if (! $node->relationLoaded('roleAssignments')) {
            return $node->roleAssignments()
                ->whereIn('role', $roles)
                ->where('status', NodeRoleStatus::Active->value)
                ->exists();
        }

        return $node->roleAssignments
            ->contains(fn (NodeRoleAssignment $assignment): bool => in_array($assignment->role, $roles, true)
                && $assignment->status === NodeRoleStatus::Active);
    }

    /**
     * @return list<int>
     */
    public function activeNodeIdsForRole(string $role): array
    {
        return $this->activeNodeIdsForRoles([$role]);
    }

    /**
     * @return list<int>
     */
    public function activeAppHostNodeIds(): array
    {
        return $this->activeNodeIdsForRoles($this->appHostRoles());
    }

    /**
     * @return list<int>
     */
    public function activeToolHostNodeIds(): array
    {
        return $this->activeNodeIdsForRoles($this->toolHostRoles());
    }

    /**
     * @return list<int>
     */
    public function activeMetricsExporterNodeIds(): array
    {
        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $this->activeAssignedNodeIds())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $nodeId): int => (int) $nodeId)
            ->all();
    }

    /**
     * @return list<int>
     */
    public function activeAgentNodeIds(): array
    {
        return $this->activeNodeIdsForRole(NodeRoleName::Agent->value);
    }

    /**
     * @return list<int>
     */
    public function activeIngressNodeIds(): array
    {
        return $this->activeNodeIdsForRole(NodeRoleName::Ingress->value);
    }

    /**
     * @return list<int>
     */
    public function activeAssignedNodeIds(): array
    {
        return NodeRoleAssignment::query()
            ->where('status', NodeRoleStatus::Active->value)
            ->distinct()
            ->orderBy('node_id')
            ->pluck('node_id')
            ->map(fn (mixed $nodeId): int => (int) $nodeId)
            ->all();
    }

    /**
     * @return list<int>
     */
    public function activeGatewayOrAppHostNodeIds(): array
    {
        return $this->activeNodeIdsForRoles($this->gatewayOrAppHostRoles());
    }

    /**
     * @param  list<string>  $roles
     * @return list<int>
     */
    public function activeNodeIdsForRoles(array $roles): array
    {
        return NodeRoleAssignment::query()
            ->whereIn('role', $roles)
            ->where('status', NodeRoleStatus::Active->value)
            ->distinct()
            ->orderBy('node_id')
            ->pluck('node_id')
            ->map(fn (mixed $nodeId): int => (int) $nodeId)
            ->all();
    }

    public function find(Node $node, string $role): ?NodeRoleAssignment
    {
        return $node->roleAssignments()
            ->where('role', $role)
            ->first();
    }

    /**
     * @return Collection<int, NodeRoleAssignment>
     */
    public function conflicting(Node $node, NodeRoleDefinition $definition): Collection
    {
        return $node->roleAssignments()
            ->whereIn('status', [
                NodeRoleStatus::Active->value,
                NodeRoleStatus::Pending->value,
                NodeRoleStatus::Error->value,
            ])
            ->whereIn('role', $definition->conflictsWith)
            ->orderBy('role')
            ->get();
    }

    public function platformSupported(NodeRoleDefinition $definition, ?string $platform): bool
    {
        $normalizedPlatform = $this->normalizePlatform($platform);

        if ($normalizedPlatform === null) {
            return false;
        }

        return in_array($normalizedPlatform, $definition->supportedPlatforms, true);
    }

    public function normalizePlatform(?string $platform): ?string
    {
        if (! is_string($platform) || trim($platform) === '') {
            return null;
        }

        return explode('_', $platform, 2)[0];
    }
}
