<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeAccess;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\Nodes\Access\NodePermissionRegistry;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function createGatewayNode(): Node
{
    return Node::factory()->gateway()->create([
        'name' => 'gateway-1',
        'status' => 'active',
    ]);
}

function createAppNode(): Node
{
    return Node::factory()->appDev()->create([
        'name' => 'app-1',
        'status' => 'active',
        'platform' => 'ubuntu',
    ]);
}

function createControlNode(): Node
{
    return Node::factory()->create([
        'name' => 'control-1',

        'status' => 'active',
    ]);
}

function grantAccess(Node $consumer, Node $serving, array $permissions = ['*']): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $serving->id,
        'permissions' => $permissions,
    ]);
}

describe('NodeAccessAuthorizer', function (): void {
    beforeEach(function (): void {
        $this->authorizer = new NodeAccessAuthorizer(
            new NodePermissionRegistry,
            app(NodeRoleAssignments::class),
        );
    });

    it('allows gateway callers for any node and permission', function (): void {
        $gateway = createGatewayNode();
        $app = createAppNode();

        expect($this->authorizer->allows($gateway, $app, 'tool:read'))->toBeTrue()
            ->and($this->authorizer->allows($gateway, $app, 'firewall_rule:write'))->toBeTrue()
            ->and($this->authorizer->allows($gateway, $gateway, 'node:new'))->toBeTrue();
    });

    it('denies non-gateway callers without any grant', function (): void {
        $control = createControlNode();
        $app = createAppNode();

        expect($this->authorizer->allows($control, $app, 'tool:read'))->toBeFalse()
            ->and($this->authorizer->allows($control, $app, 'node:read'))->toBeFalse();
    });

    it('returns authorization metadata for gateway implicit access', function (): void {
        $gateway = createGatewayNode();
        $app = createAppNode();

        $result = $this->authorizer->authorize($gateway, $app, 'tool:read');

        expect($result->allowed)->toBeTrue()
            ->and($result->missingPermission)->toBeNull()
            ->and($result->reason)->toBe('gateway_node');
    });

    it('returns authorization metadata for denied access', function (): void {
        $control = createControlNode();
        $app = createAppNode();

        $result = $this->authorizer->authorize($control, $app, 'tool:read');

        expect($result->allowed)->toBeFalse()
            ->and($result->missingPermission)->toBe('tool:read')
            ->and($result->reason)->toBe('missing_permission');
    });

    it('allows consumer->gateway with wildcard to access any node and permission', function (): void {
        $gateway = createGatewayNode();
        $control = createControlNode();
        $app = createAppNode();

        grantAccess($control, $gateway, ['*']);

        expect($this->authorizer->allows($control, $app, 'tool:read'))->toBeTrue()
            ->and($this->authorizer->allows($control, $app, 'firewall_rule:write'))->toBeTrue()
            ->and($this->authorizer->allows($control, $gateway, 'node:new'))->toBeTrue();
    });

    it('returns authorization metadata for gateway-admin grants', function (): void {
        $gateway = createGatewayNode();
        $control = createControlNode();
        $app = createAppNode();

        grantAccess($control, $gateway, ['*']);

        $result = $this->authorizer->authorize($control, $app, 'tool:read');

        expect($result->allowed)->toBeTrue()
            ->and($result->missingPermission)->toBeNull()
            ->and($result->reason)->toBe('gateway_admin_grant');
    });

    it('denies consumer->gateway without wildcard for unrelated nodes', function (): void {
        $gateway = createGatewayNode();
        $control = createControlNode();
        $app = createAppNode();

        grantAccess($control, $gateway, ['node:read']);

        expect($this->authorizer->allows($control, $app, 'tool:read'))->toBeFalse()
            ->and($this->authorizer->allows($control, $app, 'firewall_rule:write'))->toBeFalse()
            ->and($this->authorizer->allows($control, $app, 'node:read'))->toBeFalse()
            ->and($this->authorizer->allows($control, $gateway, 'node:read'))->toBeTrue();
    });

    it('allows consumer->serving grants with covering permission', function (): void {
        $control = createControlNode();
        $app = createAppNode();

        grantAccess($control, $app, ['tool:read']);

        expect($this->authorizer->allows($control, $app, 'tool:read'))->toBeTrue()
            ->and($this->authorizer->allows($control, $app, 'tool:list'))->toBeTrue();
    });

    it('returns authorization metadata for direct grants', function (): void {
        $control = createControlNode();
        $app = createAppNode();

        grantAccess($control, $app, ['tool:read']);

        $result = $this->authorizer->authorize($control, $app, 'tool:show');

        expect($result->allowed)->toBeTrue()
            ->and($result->missingPermission)->toBeNull()
            ->and($result->reason)->toBe('direct_grant');
    });

    it('denies consumer->serving grants without covering permission', function (): void {
        $control = createControlNode();
        $app = createAppNode();

        grantAccess($control, $app, ['tool:read']);

        expect($this->authorizer->allows($control, $app, 'tool:credentials'))->toBeFalse()
            ->and($this->authorizer->allows($control, $app, 'tool:install'))->toBeFalse()
            ->and($this->authorizer->allows($control, $app, 'firewall_rule:write'))->toBeFalse();
    });

    it('allows explicit self-grants with covering permission', function (): void {
        $app = createAppNode();

        grantAccess($app, $app, ['tool:read', 'tool:credentials']);

        expect($this->authorizer->allows($app, $app, 'tool:read'))->toBeTrue()
            ->and($this->authorizer->allows($app, $app, 'tool:credentials'))->toBeTrue()
            ->and($this->authorizer->allows($app, $app, 'tool:list'))->toBeTrue();
    });

    it('returns authorization metadata for self-grants', function (): void {
        $app = createAppNode();

        grantAccess($app, $app, ['tool:read']);

        $result = $this->authorizer->authorize($app, $app, 'tool:show');

        expect($result->allowed)->toBeTrue()
            ->and($result->missingPermission)->toBeNull()
            ->and($result->reason)->toBe('direct_grant');
    });

    it('denies implicit self-access without a self-grant', function (): void {
        $app = createAppNode();

        expect($this->authorizer->allows($app, $app, 'tool:read'))->toBeFalse()
            ->and($this->authorizer->allows($app, $app, 'node:read'))->toBeFalse();
    });

    it('denies self-grant when requested permission is not covered', function (): void {
        $app = createAppNode();

        grantAccess($app, $app, ['tool:read', 'tool:update']);

        expect($this->authorizer->allows($app, $app, 'tool:credentials'))->toBeFalse()
            ->and($this->authorizer->allows($app, $app, 'tool:install'))->toBeFalse()
            ->and($this->authorizer->allows($app, $app, 'firewall_rule:write'))->toBeFalse();
    });

    it('allows agent-self preset permissions', function (): void {
        $agent = Node::factory()->agent()->create([
            'name' => 'agent-1',
            'status' => 'active',
            'platform' => 'ubuntu',
        ]);

        $presets = new NodePermissionPresets;
        grantAccess($agent, $agent, $presets->permissions('agent-self'));

        expect($this->authorizer->allows($agent, $agent, 'tool:read'))->toBeTrue()
            ->and($this->authorizer->allows($agent, $agent, 'tool:update:agent-tools'))->toBeTrue()
            ->and($this->authorizer->allows($agent, $agent, 'doctor:verify'))->toBeTrue()
            ->and($this->authorizer->allows($agent, $agent, 'node:read'))->toBeTrue()
            ->and($this->authorizer->allows($agent, $agent, 'tool:list'))->toBeTrue();
    });

    it('denies agent-self preset for credentials and firewall writes', function (): void {
        $agent = Node::factory()->agent()->create([
            'name' => 'agent-1',
            'status' => 'active',
            'platform' => 'ubuntu',
        ]);

        $presets = new NodePermissionPresets;
        grantAccess($agent, $agent, $presets->permissions('agent-self'));

        expect($this->authorizer->allows($agent, $agent, 'tool:credentials'))->toBeFalse()
            ->and($this->authorizer->allows($agent, $agent, 'firewall_rule:write'))->toBeFalse()
            ->and($this->authorizer->allows($agent, $agent, 'tool:install'))->toBeFalse()
            ->and($this->authorizer->allows($agent, $agent, 'tool:remove'))->toBeFalse()
            ->and($this->authorizer->allows($agent, $agent, 'node:update'))->toBeFalse()
            ->and($this->authorizer->allows($agent, $agent, 'doctor:restore'))->toBeFalse();
    });

    it('allows operator preset for reads but denies firewall writes', function (): void {
        $control = createControlNode();
        $app = createAppNode();

        $presets = new NodePermissionPresets;
        grantAccess($control, $app, $presets->permissions('operator'));

        expect($this->authorizer->allows($control, $app, 'firewall_rule:read'))->toBeTrue()
            ->and($this->authorizer->allows($control, $app, 'database:list'))->toBeTrue()
            ->and($this->authorizer->allows($control, $app, 'database:query'))->toBeFalse()
            ->and($this->authorizer->allows($control, $app, 'tool:read'))->toBeTrue()
            ->and($this->authorizer->allows($control, $app, 'node:read'))->toBeTrue()
            ->and($this->authorizer->allows($control, $app, 'firewall_rule:write'))->toBeFalse()
            ->and($this->authorizer->allows($control, $app, 'doctor:restore'))->toBeFalse();
    });

    it('falls back to wildcard for grants without explicit permissions', function (): void {
        $control = createControlNode();
        $app = createAppNode();

        NodeAccess::query()->create([
            'consumer_node_id' => $control->id,
            'serving_node_id' => $app->id,
        ]);

        expect($this->authorizer->allows($control, $app, 'tool:read'))->toBeTrue()
            ->and($this->authorizer->allows($control, $app, 'firewall_rule:write'))->toBeTrue();
    });
});
