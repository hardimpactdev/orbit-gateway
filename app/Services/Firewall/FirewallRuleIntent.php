<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeStatus;
use App\Http\Gateway\GatewayApiException;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;

class FirewallRuleIntent
{
    public function __construct(
        private readonly FirewallRuleQuery $query,
        private readonly FirewallRuleFixer $fixer,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function store(string $action, string $name, string $nodeName, string $direction, string $source, ?string $destination, string $port, string $protocol, ?string $reason, ?Node $caller = null): array
    {
        $node = $this->resolveTargetNode($nodeName, $caller);
        $this->validateShape($action, $direction, $source, $destination, $port, $protocol);
        $this->guardBaselinePolicy($direction, $action, $source, $destination, $port, $protocol);

        $existing = FirewallRule::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->where('name', $name)
            ->first();

        $shape = [
            'direction' => $direction,
            'action' => $action,
            'source' => $source,
            'destination' => $destination,
            'port' => $port,
            'protocol' => $protocol,
        ];

        if ($existing instanceof FirewallRule && ! $this->sameShape($existing, $shape)) {
            throw new GatewayApiException('A different firewall rule already uses this name on the selected node.', 'firewall_rule.name_collision', [
                'name' => $name,
                'node' => $node->name,
            ]);
        }

        $rule = FirewallRule::query()->updateOrCreate(
            ['node_id' => $node->id, 'name' => $name],
            [
                ...$shape,
                'reason' => $reason,
                'source_hash' => $this->sourceHash($node->name, $name, $shape, $reason),
                'owner' => 'user',
                'protected' => false,
            ],
        );

        $backendEnacted = true;
        $warnings = [];

        try {
            $this->fixer->fix($rule->refresh(), new DriftEntry(
                family: 'firewall_rule',
                key: 'firewall_rule.rule_missing',
                kind: DriftKind::Missing,
                summary: "Apply firewall rule {$rule->name}.",
            ));
        } catch (\Throwable $exception) {
            if (! $this->shouldDeferBackendMutation()) {
                throw new GatewayApiException('Firewall rule intent was saved, but backend enactment failed.', 'firewall_rule.enactment_failed', [
                    'node' => $node->name,
                    'rule' => $name,
                    'reason' => $exception->getMessage(),
                ]);
            }

            $backendEnacted = false;
            $warnings[] = $this->runtimeWarning($node->name);
        }

        return [
            'data' => [
                'rule' => $this->query->toRuleEntity($rule->refresh(), 'expected'),
            ],
            'meta' => [
                'action' => $existing instanceof FirewallRule ? 'converged' : 'created',
                'backend_enacted' => $backendEnacted,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function remove(string $name, string $nodeName, ?Node $caller = null): array
    {
        $node = $this->resolveTargetNode($nodeName, $caller);

        $rule = FirewallRule::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->where('name', $name)
            ->first();

        if (! $rule instanceof FirewallRule) {
            return [
                'data' => [
                    'rule' => [
                        'name' => $name,
                        'node' => $node->name,
                        'direction' => null,
                        'action' => null,
                        'source' => null,
                        'destination' => null,
                        'port' => null,
                        'protocol' => null,
                        'reason' => null,
                        'status' => 'already_absent',
                    ],
                ],
                'meta' => [
                    'backend_removed' => false,
                    'warnings' => [],
                ],
            ];
        }

        if ($rule->protected) {
            throw new GatewayApiException('Protected firewall rules cannot be removed through firewall commands.', 'firewall_rule.protected', [
                'name' => $rule->name,
                'node' => $node->name,
                'owner' => $rule->owner,
            ]);
        }

        $entity = $this->query->toRuleEntity($rule, 'removed_with_drift');
        $rule->delete();

        $backendRemoved = true;
        $warnings = [];

        try {
            $this->fixer->remove($rule);
        } catch (\Throwable $exception) {
            if (! $this->shouldDeferBackendMutation()) {
                throw new GatewayApiException('Firewall rule intent was removed, but backend cleanup failed.', 'firewall_rule.cleanup_failed', [
                    'node' => $node->name,
                    'rule' => $name,
                    'reason' => $exception->getMessage(),
                ]);
            }

            $backendRemoved = false;
            $warnings[] = $this->cleanupWarning($node->name);
        }

        return [
            'data' => [
                'rule' => $entity,
            ],
            'meta' => [
                'backend_removed' => $backendRemoved,
                'warnings' => $warnings,
            ],
        ];
    }

    private function resolveTargetNode(string $nodeName, ?Node $caller): Node
    {
        $node = Node::query()
            ->where('name', $nodeName)
            ->where('status', NodeStatus::Active->value)
            ->where('platform', 'ubuntu')
            ->whereIn('id', app(NodeRoleAssignments::class)->activeNodeIdsForRoles($this->eligibleTargetRoles()))
            ->first();

        if (! $node instanceof Node) {
            throw new GatewayApiException('The selected node is not a firewall target.', 'validation_failed', [
                'field' => 'node',
                'node' => $nodeName,
            ]);
        }

        $this->authorizeTargetNode($node, $caller);

        return $node;
    }

    private function authorizeTargetNode(Node $node, ?Node $caller): void
    {
        if (! $caller instanceof Node || app(NodeRoleAssignments::class)->nodeIsGateway($caller)) {
            return;
        }

        $result = app(NodeAccessAuthorizer::class)->authorize($caller, $node, 'firewall_rule:write');

        if ($result->allowed) {
            return;
        }

        throw new GatewayApiException('This node is not authorized to manage firewall rules for the selected node.', 'authorization_failed', [
            'node' => $node->name,
            'reason' => $result->reason,
            'missing_permission' => $result->missingPermission,
            'serving_node' => $node->name,
        ]);
    }

    private function validateShape(string $action, string $direction, string $source, ?string $destination, string $port, string $protocol): void
    {
        if (! in_array($action, ['allow', 'deny'], true)) {
            throw new GatewayApiException('The firewall rule action is invalid.', 'validation_failed', ['field' => 'action']);
        }

        if (! in_array($direction, ['incoming', 'outgoing'], true)) {
            throw new GatewayApiException('The firewall rule direction is invalid.', 'validation_failed', ['field' => 'direction']);
        }

        if (! in_array($protocol, ['tcp', 'udp'], true)) {
            throw new GatewayApiException('The firewall rule protocol is invalid.', 'validation_failed', ['field' => 'protocol']);
        }

        if (! $this->validEndpoint($source) || ($destination !== null && ! $this->validEndpoint($destination))) {
            throw new GatewayApiException('The firewall rule endpoint is invalid.', 'validation_failed', ['field' => 'source']);
        }

        if (! preg_match('/^\d{1,5}(:\d{1,5})?$/', $port)) {
            throw new GatewayApiException('The firewall rule port is invalid.', 'validation_failed', ['field' => 'port']);
        }
    }

    private function validEndpoint(string $value): bool
    {
        return $value === 'any'
            || filter_var($value, FILTER_VALIDATE_IP) !== false
            || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            || str_contains($value, '/');
    }

    private function guardBaselinePolicy(string $direction, string $action, string $source, ?string $destination, string $port, string $protocol): void
    {
        if ($direction === 'incoming' && $action === 'allow' && $source === 'any' && $destination === null && $protocol === 'tcp' && $port === '22') {
            throw new GatewayApiException('The requested rule would mutate node bootstrap policy.', 'firewall_rule.baseline_conflict', [
                'port' => $port,
                'protocol' => $protocol,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function eligibleTargetRoles(): array
    {
        return app(NodeRoleAssignments::class)->firewallEligibleRoles();
    }

    /**
     * @param  array<string, string|null>  $shape
     */
    private function sameShape(FirewallRule $rule, array $shape): bool
    {
        return $rule->direction === $shape['direction']
            && $rule->action === $shape['action']
            && $rule->source === $shape['source']
            && $rule->destination === $shape['destination']
            && $rule->port === $shape['port']
            && $rule->protocol === $shape['protocol'];
    }

    /**
     * @param  array<string, string|null>  $shape
     */
    private function sourceHash(string $node, string $name, array $shape, ?string $reason): string
    {
        return hash('sha256', json_encode([
            'node' => $node,
            'name' => $name,
            'shape' => $shape,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));
    }

    private function shouldDeferBackendMutation(): bool
    {
        $provider = $this->e2eEnvironmentValue('ORBIT_E2E_TOPOLOGY_PROVIDER');

        if ($provider !== null) {
            return strtolower(trim($provider)) === 'docker';
        }

        $providers = $this->e2eEnvironmentValue('ORBIT_E2E_TOPOLOGY_PROVIDERS');

        if ($providers === null) {
            return false;
        }

        return in_array('docker', array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', $providers),
        ), true);
    }

    private function e2eEnvironmentValue(string $key): ?string
    {
        $processValue = getenv($key);

        if (is_string($processValue) && $processValue !== '') {
            return $processValue;
        }

        $serverValue = $_SERVER[$key] ?? null;

        if (is_string($serverValue) && $serverValue !== '') {
            return $serverValue;
        }

        $envValue = $_ENV[$key] ?? null;

        return is_string($envValue) && $envValue !== '' ? $envValue : null;
    }

    /**
     * @return array{code: string, family: string, node: string, message: string, next_command: string}
     */
    private function runtimeWarning(string $nodeName): array
    {
        return [
            'code' => 'firewall_rule.enactment_deferred',
            'family' => 'firewall_rule',
            'node' => $nodeName,
            'message' => 'Firewall rule intent was saved, but backend enactment is deferred in this runtime.',
            'next_command' => 'doctor --family=firewall_rule --restore',
        ];
    }

    /**
     * @return array{code: string, family: string, node: string, message: string, next_command: string}
     */
    private function cleanupWarning(string $nodeName): array
    {
        return [
            'code' => 'firewall_rule.cleanup_deferred',
            'family' => 'firewall_rule',
            'node' => $nodeName,
            'message' => 'Firewall rule intent was removed, but backend cleanup is deferred in this runtime.',
            'next_command' => 'doctor --family=firewall_rule --restore',
        ];
    }
}
