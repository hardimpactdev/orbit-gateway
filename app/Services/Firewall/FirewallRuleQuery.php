<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use App\Enums\Nodes\NodeStatus;
use App\Http\Gateway\GatewayApiException;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class FirewallRuleQuery
{
    /**
     * @return array{
     *     rules: list<array<string, mixed>>,
     *     meta: array{node: ?string, count: int},
     * }
     */
    public function list(?string $node = null, ?Node $caller = null): array
    {
        $node = $node !== null && trim($node) !== '' ? trim($node) : null;
        $visibleNodeIds = $this->visibleNodeIds($caller);

        if ($caller instanceof Node && ! app(NodeRoleAssignments::class)->nodeIsGateway($caller) && $visibleNodeIds === []) {
            throw new GatewayApiException(
                message: 'This node is not authorized to read the firewall rule registry.',
                errorCode: 'authorization_failed',
                errorMeta: [
                    'reason' => 'missing_permission',
                    'missing_permission' => 'firewall_rule:read',
                ],
            );
        }

        $nodeId = $this->resolveNodeId($node, $caller, $visibleNodeIds);

        /** @var Collection<int, FirewallRule> $firewallRules */
        $firewallRules = FirewallRule::query()
            ->with('node')
            ->whereHas('node', fn (Builder $query): Builder => $this->eligibleNodeQuery($query))
            ->when($caller instanceof Node && ! app(NodeRoleAssignments::class)->nodeIsGateway($caller), fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds))
            ->when($nodeId !== null, fn (Builder $query): Builder => $query->where('node_id', $nodeId))
            ->get();

        $rules = $firewallRules
            ->sort(fn (FirewallRule $first, FirewallRule $second): int => [
                mb_strtolower($first->node->name),
                mb_strtolower($first->name),
            ] <=> [
                mb_strtolower($second->node->name),
                mb_strtolower($second->name),
            ])
            ->values()
            ->map(fn (FirewallRule $rule): array => $this->toRuleEntity($rule))
            ->all();

        return [
            'rules' => $rules,
            'meta' => [
                'node' => $node,
                'count' => count($rules),
            ],
        ];
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function resolveNodeId(?string $node, ?Node $caller, array $visibleNodeIds): ?int
    {
        if ($node === null) {
            return null;
        }

        $query = Node::query()
            ->where('name', $node)
            ->where(fn (Builder $query): Builder => $this->eligibleNodeQuery($query));

        if ($caller instanceof Node && ! app(NodeRoleAssignments::class)->nodeIsGateway($caller)) {
            $query->whereIn('id', $visibleNodeIds);
        }

        $nodeId = $query->value('id');

        if (is_int($nodeId)) {
            return $nodeId;
        }

        throw new GatewayApiException(
            message: 'The selected node is not a firewall target.',
            errorCode: 'validation_failed',
            errorMeta: [
                'field' => 'node',
                'node' => $node,
            ],
        );
    }

    private function eligibleNodeQuery(Builder $query): Builder
    {
        return $query
            ->where('status', NodeStatus::Active->value)
            ->where('platform', 'ubuntu')
            ->whereIn('id', app(NodeRoleAssignments::class)->activeNodeIdsForRoles($this->eligibleTargetRoles()));
    }

    /**
     * @return list<string>
     */
    private function eligibleTargetRoles(): array
    {
        return app(NodeRoleAssignments::class)->firewallEligibleRoles();
    }

    /**
     * @return list<int>
     */
    private function visibleNodeIds(?Node $caller): array
    {
        if (! $caller instanceof Node || app(NodeRoleAssignments::class)->nodeIsGateway($caller)) {
            return Node::query()
                ->where(fn (Builder $query): Builder => $this->eligibleNodeQuery($query))
                ->pluck('id')
                ->all();
        }

        $authorizer = app(NodeAccessAuthorizer::class);
        $eligibleNodes = Node::query()
            ->where(fn (Builder $query): Builder => $this->eligibleNodeQuery($query))
            ->get();

        $visibleNodeIds = [];

        foreach ($eligibleNodes as $node) {
            if ($authorizer->allows($caller, $node, 'firewall_rule:read')) {
                $visibleNodeIds[] = $node->id;
            }
        }

        return $visibleNodeIds;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRuleEntity(FirewallRule $rule, ?string $status = null): array
    {
        $rule->loadMissing('node');

        return [
            'name' => $rule->name,
            'node' => $rule->node->name,
            'direction' => $rule->direction,
            'action' => $rule->action,
            'source' => $rule->source,
            'destination' => $rule->destination,
            'port' => is_numeric($rule->port) ? (int) $rule->port : $rule->port,
            'protocol' => $rule->protocol,
            'address_family' => $rule->address_family,
            'interface' => $rule->interface,
            'owner' => $rule->owner,
            'protected' => $rule->protected,
            'reason' => $rule->reason,
            'status' => $status ?? 'expected',
        ];
    }
}
