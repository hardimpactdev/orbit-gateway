<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\WebSockets\WebSocketRedisResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class NodeRoleAssignmentService
{
    public function __construct(
        private readonly NodeRoleRegistry $registry,
        private readonly NodeRoleAssignments $assignments,
        private readonly NodeRoleBaselineConverger $converger,
        private readonly NodeRoleDependencyInspector $dependencyInspector,
        private readonly RoleSelfGrantMaterializer $roleSelfGrantMaterializer,
        private readonly WebSocketRedisResolver $webSocketRedisResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     */
    public function add(Node $node, string $role, array $settings): NodeRoleAssignment
    {
        $this->guardNotGatewayCoupledInfrastructureRole($role);

        $definition = $this->registry->definition($role);

        if (! $definition->assignableByRoleCommand) {
            throw new InvalidArgumentException("Role '{$role}' cannot be assigned through this service.");
        }

        return $this->persistAndConverge($node, $role, $settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function addDuringCreation(Node $node, string $role, array $settings): NodeRoleAssignment
    {
        $this->guardNotGatewayCoupledInfrastructureRole($role);

        $definition = $this->registry->definition($role);

        if (! $definition->assignableByNodeNew) {
            throw new InvalidArgumentException("Role '{$role}' cannot be assigned during node creation.");
        }

        return $this->persistAndConverge($node, $role, $settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function persistAndConverge(Node $node, string $role, array $settings): NodeRoleAssignment
    {
        $definition = $this->registry->definition($role);

        if ($this->assignments->find($node, $role) instanceof NodeRoleAssignment) {
            throw new InvalidArgumentException("Role '{$role}' is already assigned to node '{$node->name}'.");
        }

        $this->guardSupportedPlatform($node, $definition);
        $this->guardAgainstConflicts($node, $definition);

        $settingsData = $definition->settingsFromArray($settings)->toArray();
        $this->guardWebSocketRedisNode($role, $settingsData);
        $this->guardAppProductionIngressNode($node, $role, $settingsData);
        $this->guardUniqueDevelopmentTld($node, $role, $settingsData);

        $assignment = $node->roleAssignments()->create([
            'role' => $role,
            'status' => NodeRoleStatus::Pending->value,
            'settings' => $settingsData,
            'last_error' => null,
            'converged_at' => null,
        ]);

        return $this->converge($node, $assignment);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function update(Node $node, string $role, array $settings): NodeRoleAssignment
    {
        $this->guardNotGatewayCoupledInfrastructureRole($role);

        $definition = $this->registry->definition($role);

        if (! $definition->assignableByRoleCommand) {
            throw new InvalidArgumentException("Role '{$role}' cannot be updated through this service.");
        }

        $assignment = $this->assignments->find($node, $role);

        if (! $assignment instanceof NodeRoleAssignment) {
            throw new InvalidArgumentException("Role '{$role}' is not assigned to node '{$node->name}'.");
        }

        $this->guardSupportedPlatform($node, $definition);
        $this->guardAgainstConflicts($node, $definition);

        $settingsData = $definition->settingsFromArray($settings)->toArray();
        $this->guardWebSocketRedisNode($role, $settingsData);
        $this->guardAppProductionIngressNode($node, $role, $settingsData);
        $this->guardUniqueDevelopmentTld($node, $role, $settingsData);

        $previousAssignment = $assignment->replicate();

        $assignment->forceFill([
            'settings' => $settingsData,
            'status' => NodeRoleStatus::Pending->value,
            'last_error' => null,
            'converged_at' => null,
        ])->save();

        return $this->converge($node, $assignment->fresh() ?? $assignment, $previousAssignment);
    }

    public function remove(Node $node, string $role, bool $force = false, bool $purgeData = false): void
    {
        $this->guardNotGatewayCoupledInfrastructureRole($role);

        $definition = $this->registry->definition($role);

        if ($purgeData && ! $force) {
            throw new InvalidArgumentException('The purgeData option requires force.');
        }

        if (! $definition->assignableByRoleCommand) {
            throw new InvalidArgumentException("Role '{$role}' cannot be removed through this service.");
        }

        $assignment = $this->assignments->find($node, $role);

        if (! $assignment instanceof NodeRoleAssignment) {
            throw new InvalidArgumentException("Role '{$role}' is not assigned to node '{$node->name}'.");
        }

        $dependents = $this->dependencyInspector->dependentSummaries($node, $assignment);

        if ($dependents !== [] && ! $force) {
            throw new InvalidArgumentException("Role '{$role}' cannot be removed while dependents exist.");
        }

        try {
            DB::transaction(function () use ($node, $assignment, $force, $purgeData, $role): void {
                $transactionAssignment = NodeRoleAssignment::query()
                    ->lockForUpdate()
                    ->findOrFail($assignment->id);

                $transactionDependents = $this->dependencyInspector->dependentSummaries($node, $transactionAssignment);

                if ($transactionDependents !== [] && ! $force) {
                    throw new InvalidArgumentException("Role '{$role}' cannot be removed while dependents exist.");
                }

                $transactionAssignment->forceFill([
                    'status' => NodeRoleStatus::Removing->value,
                    'last_error' => null,
                ])->save();

                if ($force && $transactionDependents !== []) {
                    $this->dependencyInspector->removeOrbitOwnedDependents($node, $transactionAssignment);
                }

                $this->converger->remove($node, $transactionAssignment, $purgeData);

                $transactionAssignment->delete();

                $this->syncNodeTldFromRoles($node);
                $this->roleSelfGrantMaterializer->reconcileOnRoleRemoved($node, NodeRoleName::from($role));
            });
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            NodeRoleAssignment::query()
                ->whereKey($assignment->id)
                ->update([
                    'status' => NodeRoleStatus::Error->value,
                    'last_error' => $throwable->getMessage(),
                ]);

            throw $throwable;
        }
    }

    private function guardNotGatewayCoupledInfrastructureRole(string $role): void
    {
        if (! in_array($role, [NodeRoleName::Gateway->value, NodeRoleName::Vpn->value, NodeRoleName::Router->value], true)) {
            return;
        }

        throw new InvalidArgumentException("Role '{$role}' is gateway-coupled and cannot be assigned independently.");
    }

    private function converge(Node $node, NodeRoleAssignment $assignment, ?NodeRoleAssignment $previousAssignment = null): NodeRoleAssignment
    {
        try {
            $this->converger->converge($node, $assignment);
            $this->removePreviousDevelopmentDnsMapping($node, $assignment, $previousAssignment);

            $assignment->forceFill([
                'status' => NodeRoleStatus::Active->value,
                'converged_at' => now(),
                'last_error' => null,
            ])->save();

            $this->syncNodeTldFromRoles($node);
            $this->roleSelfGrantMaterializer->materializeOnRoleApplied($node, NodeRoleName::from($assignment->role));
        } catch (Throwable $throwable) {
            $assignment->forceFill([
                'status' => NodeRoleStatus::Error->value,
                'last_error' => $throwable->getMessage(),
                'converged_at' => null,
            ])->save();
        }

        /** @var NodeRoleAssignment $freshAssignment */
        $freshAssignment = $assignment->fresh();

        return $freshAssignment;
    }

    private function removePreviousDevelopmentDnsMapping(Node $node, NodeRoleAssignment $assignment, ?NodeRoleAssignment $previousAssignment): void
    {
        if (! $previousAssignment instanceof NodeRoleAssignment) {
            return;
        }

        if (! in_array($assignment->role, [NodeRoleName::AppDevelopment->value, NodeRoleName::Agent->value], true)) {
            return;
        }

        $previousTld = $previousAssignment->settings['tld'] ?? null;
        $currentTld = $assignment->settings['tld'] ?? null;

        if (! is_string($previousTld) || ! is_string($currentTld) || $previousTld === $currentTld) {
            return;
        }

        $this->converger->remove($node, $previousAssignment, purgeData: false);
    }

    private function syncNodeTldFromRoles(Node $node): void
    {
        $activeAssignments = $node->roleAssignments()
            ->where('status', NodeRoleStatus::Active->value)
            ->orderBy('role')
            ->get();

        $tld = null;

        $appDevelopment = $activeAssignments->firstWhere('role', NodeRoleName::AppDevelopment->value);
        $agent = $activeAssignments->firstWhere('role', NodeRoleName::Agent->value);
        $database = $activeAssignments->firstWhere('role', NodeRoleName::Database->value);

        if ($appDevelopment instanceof NodeRoleAssignment) {
            $developmentTld = $appDevelopment->settings['tld'] ?? null;
            $tld = is_string($developmentTld) ? $developmentTld : null;
        } elseif ($agent instanceof NodeRoleAssignment) {
            $agentTld = $agent->settings['tld'] ?? null;
            $tld = is_string($agentTld) ? $agentTld : null;
        } elseif ($database instanceof NodeRoleAssignment) {
            $nodeTld = is_string($node->tld) ? trim($node->tld) : '';
            $tld = $nodeTld !== '' ? $nodeTld : null;
        }

        $node->forceFill(['tld' => $tld])->save();

        $node->unsetRelation('roleAssignments');
    }

    private function guardSupportedPlatform(Node $node, NodeRoleDefinition $definition): void
    {
        if ($this->assignments->platformSupported($definition, $node->platform)) {
            return;
        }

        throw new InvalidArgumentException("Role '{$definition->name}' does not support platform '{$node->platform}'.");
    }

    private function guardAgainstConflicts(Node $node, NodeRoleDefinition $definition): void
    {
        $conflict = $this->assignments->conflicting($node, $definition)->first();

        if (! $conflict instanceof NodeRoleAssignment) {
            return;
        }

        throw new InvalidArgumentException("Role '{$definition->name}' conflicts with {$conflict->status->value} role '{$conflict->role}'.");
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function guardWebSocketRedisNode(string $role, array $settings): void
    {
        if ($role !== NodeRoleName::WebSocket->value) {
            return;
        }

        $redisNodeId = $settings['redis_node_id'] ?? null;

        if (is_int($redisNodeId) && $this->webSocketRedisResolver->usableRedisNode($redisNodeId) instanceof Node) {
            return;
        }

        throw new InvalidArgumentException('The websocket role requires redis_node_id to reference an active database node with a Redis process.');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function guardAppProductionIngressNode(Node $node, string $role, array $settings): void
    {
        if ($role !== NodeRoleName::AppProduction->value) {
            return;
        }

        $ingressNodeId = $settings['ingress_node_id'] ?? null;

        if (! is_int($ingressNodeId) || $ingressNodeId <= 0) {
            throw new InvalidArgumentException('The app-prod role requires an active ingress node.');
        }

        if ($ingressNodeId === $node->id && $this->nodeHasActiveIngressAssignment($node)) {
            return;
        }

        $ingressNode = Node::query()->find($ingressNodeId);

        if (! $ingressNode instanceof Node || ! $this->nodeCanServeIngress($ingressNode)) {
            throw new InvalidArgumentException('The app-prod role requires an active ingress node.');
        }
    }

    private function nodeCanServeIngress(Node $node): bool
    {
        if (! $node->isActive()) {
            return false;
        }

        return $this->nodeHasActiveIngressAssignment($node);
    }

    private function nodeHasActiveIngressAssignment(Node $node): bool
    {
        return $node->roleAssignments()
            ->where('role', NodeRoleName::Ingress->value)
            ->where('status', NodeRoleStatus::Active->value)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function guardUniqueDevelopmentTld(Node $node, string $role, array $settings): void
    {
        if (! in_array($role, [NodeRoleName::AppDevelopment->value, NodeRoleName::Agent->value], true)) {
            return;
        }

        $tld = $settings['tld'] ?? null;

        if (! is_string($tld) || $tld === '') {
            return;
        }

        $nodeTldCollision = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->where('tld', $tld)
            ->whereKeyNot($node->id)
            ->exists();

        $roleCollision = NodeRoleAssignment::query()
            ->whereIn('role', [NodeRoleName::AppDevelopment->value, NodeRoleName::Agent->value])
            ->where('status', NodeRoleStatus::Active->value)
            ->where('node_id', '!=', $node->id)
            ->where('settings->tld', $tld)
            ->whereRelation('node', 'status', 'active')
            ->exists();

        if (! $nodeTldCollision && ! $roleCollision) {
            return;
        }

        throw new InvalidArgumentException("Development TLD '{$tld}' is already assigned to another node.");
    }
}
