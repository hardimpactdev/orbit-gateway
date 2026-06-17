<?php

declare(strict_types=1);

use App\Http\Gateway\GatewayApiException;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Firewall\FirewallRuleQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 */
function grantFirewallRuleQueryAccess(Node $caller, Node $servingNode, array $permissions = ['*']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'permissions' => json_encode($permissions),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignFirewallRuleQueryAppHostRole(Node $node, string $role = 'app-dev', array $settings = ['tld' => 'test']): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

function assignFirewallRuleQueryRole(Node $node, string $role): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => [],
    ]);
}

describe('FirewallRuleQuery', function (): void {
    it('normalizes firewall rule entities and sorts them by node then name', function (): void {
        $aNode = Node::factory()->create(['name' => 'a-node', 'platform' => 'ubuntu']);
        $zNode = Node::factory()->create(['name' => 'z-node', 'platform' => 'ubuntu']);
        assignFirewallRuleQueryAppHostRole($aNode);
        assignFirewallRuleQueryAppHostRole($zNode);

        FirewallRule::factory()->create([
            'node_id' => $zNode->id,
            'name' => 'vite',
            'port' => '5173',
            'reason' => 'local development server',
        ]);
        FirewallRule::factory()->create([
            'node_id' => $aNode->id,
            'name' => 'https',
            'port' => '443',
            'reason' => null,
        ]);

        $result = app(FirewallRuleQuery::class)->list();

        expect(array_map(fn (array $rule): string => "{$rule['node']}:{$rule['name']}", $result['rules']))->toBe([
            'a-node:https',
            'z-node:vite',
        ])
            ->and($result['meta'])->toBe([
                'node' => null,
                'count' => 2,
            ])
            ->and($result['rules'][0])->toMatchArray([
                'name' => 'https',
                'node' => 'a-node',
                'direction' => 'incoming',
                'action' => 'allow',
                'source' => 'any',
                'destination' => null,
                'port' => 443,
                'protocol' => 'tcp',
                'reason' => null,
                'status' => 'expected',
            ]);
    });

    it('filters by visible eligible node and rejects unsupported node scopes', function (): void {
        $caller = Node::factory()->appDev()->create(['platform' => 'ubuntu']);
        $visibleNode = Node::factory()->create(['name' => 'visible-node', 'platform' => 'ubuntu']);
        $hiddenNode = Node::factory()->create(['name' => 'hidden-node', 'platform' => 'ubuntu']);
        assignFirewallRuleQueryAppHostRole($visibleNode, 'app-prod', []);
        assignFirewallRuleQueryAppHostRole($hiddenNode);
        grantFirewallRuleQueryAccess($caller, $visibleNode);

        FirewallRule::factory()->create(['node_id' => $visibleNode->id, 'name' => 'visible']);
        FirewallRule::factory()->create(['node_id' => $hiddenNode->id, 'name' => 'hidden']);

        $query = app(FirewallRuleQuery::class);
        $result = $query->list(node: 'visible-node', caller: $caller);

        expect(array_column($result['rules'], 'name'))->toBe(['visible'])
            ->and($result['meta']['node'])->toBe('visible-node');

        $query->list(node: 'hidden-node', caller: $caller);
    })->throws(GatewayApiException::class, 'The selected node is not a firewall target.');

    it('allows non-gateway callers that have firewall read permission', function (): void {
        $caller = Node::factory()->appDev()->create(['platform' => 'ubuntu']);
        $visibleNode = Node::factory()->create(['name' => 'visible-node', 'platform' => 'ubuntu']);
        assignFirewallRuleQueryAppHostRole($visibleNode);
        grantFirewallRuleQueryAccess($caller, $visibleNode, ['firewall_rule:read']);

        FirewallRule::factory()->create(['node_id' => $visibleNode->id, 'name' => 'visible']);

        $result = app(FirewallRuleQuery::class)->list(caller: $caller);

        expect(array_column($result['rules'], 'name'))->toBe(['visible']);
    });

    it('omits rules for inactive unsupported or role-incompatible nodes', function (): void {
        $eligibleNode = Node::factory()->create(['name' => 'app-1', 'platform' => 'ubuntu']);
        $controlNode = Node::factory()->create(['name' => 'control-1', 'platform' => 'ubuntu']);
        $macNode = Node::factory()->appDev()->create(['name' => 'mac-1', 'platform' => 'macos']);
        $inactiveNode = Node::factory()->appDev()->create(['name' => 'inactive-1', 'platform' => 'ubuntu', 'status' => 'inactive']);
        $unassignedAppOnlyNode = Node::factory()->create(['name' => 'unassigned-app-only', 'platform' => 'ubuntu']);
        assignFirewallRuleQueryAppHostRole($eligibleNode);

        FirewallRule::factory()->create(['node_id' => $eligibleNode->id, 'name' => 'visible']);
        FirewallRule::factory()->create(['node_id' => $controlNode->id, 'name' => 'control']);
        FirewallRule::factory()->create(['node_id' => $macNode->id, 'name' => 'mac']);
        FirewallRule::factory()->create(['node_id' => $inactiveNode->id, 'name' => 'inactive']);
        FirewallRule::factory()->create(['node_id' => $unassignedAppOnlyNode->id, 'name' => 'unassigned']);

        $result = app(FirewallRuleQuery::class)->list();

        expect(array_column($result['rules'], 'name'))->toBe(['visible']);
    });

    it('lists firewall rules for every active Ubuntu role target', function (): void {
        foreach (['gateway', 'vpn', 'router', 'app-dev', 'app-prod', 'database', 'agent', 'ingress', 'websocket', 's3'] as $role) {
            $node = Node::factory()->create(['name' => "{$role}-node", 'platform' => 'ubuntu', 'status' => 'active']);
            assignFirewallRuleQueryRole($node, $role);
            FirewallRule::factory()->create(['node_id' => $node->id, 'name' => "{$role}-rule"]);
        }

        $result = app(FirewallRuleQuery::class)->list();

        expect(array_column($result['rules'], 'name'))->toBe([
            'agent-rule',
            'app-dev-rule',
            'app-prod-rule',
            'database-rule',
            'gateway-rule',
            'ingress-rule',
            'router-rule',
            's3-rule',
            'vpn-rule',
            'websocket-rule',
        ]);
    });

    it('fails authorization when non-gateway callers have no visible firewall nodes', function (): void {
        $caller = Node::factory()->appDev()->create(['platform' => 'ubuntu']);

        app(FirewallRuleQuery::class)->list(caller: $caller);
    })->throws(GatewayApiException::class, 'This node is not authorized to read the firewall rule registry.');
});
