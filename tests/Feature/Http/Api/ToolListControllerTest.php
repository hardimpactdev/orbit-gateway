<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const TOOL_LIST_CALLER_WG_IP = '10.6.0.97';

function createToolListCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => TOOL_LIST_CALLER_WG_IP,
        'wireguard_address' => TOOL_LIST_CALLER_WG_IP], $overrides));
}

function grantToolListAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now()]);
}

function assignToolListGatewayRole(Node $node): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active']);
}

describe('ToolListController', function (): void {
    it('lists visible tools sorted by owning node then tool name', function (): void {
        $caller = createToolListCallerNode();
        $zNode = createTestAppHostNode(['name' => 'z-node']);
        $aNode = createTestAppHostNode(['name' => 'a-node']);
        grantToolListAccess($caller, $zNode);
        grantToolListAccess($caller, $aNode);

        NodeTool::factory()->create(['name' => 'composer', 'node_id' => $zNode->id]);
        NodeTool::factory()->create(['name' => 'php', 'node_id' => $aNode->id]);
        NodeTool::factory()->create(['name' => 'caddy', 'node_id' => $aNode->id]);

        $response = $this->call('GET', '/api/tools', [], [], [], ['REMOTE_ADDR' => TOOL_LIST_CALLER_WG_IP]);

        $response->assertOk();

        $tools = $response->json('success.data.tools');
        expect(array_map(fn (array $tool): string => "{$tool['node']}:{$tool['name']}", $tools))->toBe([
            'a-node:caddy',
            'a-node:php',
            'z-node:composer']);
    });

    it('filters tools by owning node', function (): void {
        $caller = createToolListCallerNode();
        $firstNode = createTestAppHostNode(['name' => 'app-1']);
        $secondNode = createTestAppHostNode(['name' => 'app-2']);
        grantToolListAccess($caller, $firstNode);
        grantToolListAccess($caller, $secondNode);

        NodeTool::factory()->create(['name' => 'composer', 'node_id' => $firstNode->id]);
        NodeTool::factory()->create(['name' => 'php', 'node_id' => $secondNode->id]);

        $response = $this->call('GET', '/api/tools?node=app-2', [], [], [], ['REMOTE_ADDR' => TOOL_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.tools')
            ->assertJsonPath('success.data.tools.0.name', 'php');
    });

    it('filters tools by app owning node', function (): void {
        $caller = createToolListCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantToolListAccess($caller, $node);

        App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => 'docs.example.com']);
        NodeTool::factory()->create(['name' => 'composer', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/tools?app=docs.example.com', [], [], [], ['REMOTE_ADDR' => TOOL_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.tools')
            ->assertJsonPath('success.data.tools.0.node', 'app-1');
    });

    it('omits hidden tools from the result', function (): void {
        $caller = createToolListCallerNode();
        $visibleNode = createTestAppHostNode(['name' => 'visible-node']);
        $hiddenNode = createTestAppHostNode(['name' => 'hidden-node']);
        grantToolListAccess($caller, $visibleNode);

        NodeTool::factory()->create(['name' => 'composer', 'node_id' => $visibleNode->id]);
        NodeTool::factory()->create(['name' => 'php', 'node_id' => $hiddenNode->id]);

        $response = $this->call('GET', '/api/tools', [], [], [], ['REMOTE_ADDR' => TOOL_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.tools')
            ->assertJsonPath('success.data.tools.0.name', 'composer');
    });

    it('lets active gateway role assignments read all active tool host records', function (): void {
        $caller = createToolListCallerNode([]);
        assignToolListGatewayRole($caller);
        $firstNode = createTestAppHostNode(['name' => 'app-1']);
        $secondNode = createTestAppHostNode(['name' => 'app-2']);
        $controlNode = Node::factory()->create(['name' => 'control-1']);

        NodeTool::factory()->create(['name' => 'composer', 'node_id' => $firstNode->id]);
        NodeTool::factory()->create(['name' => 'php', 'node_id' => $secondNode->id]);
        NodeTool::factory()->create(['name' => 'gh', 'node_id' => $controlNode->id]);

        $response = $this->call('GET', '/api/tools', [], [], [], ['REMOTE_ADDR' => TOOL_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(2, 'success.data.tools');
    });

    it('does not treat unassigned nodes as gateway-visible tool hosts', function (): void {
        createToolListCallerNode([]);
        $node = createTestAppHostNode(['name' => 'app-1']);
        NodeTool::factory()->create(['name' => 'composer', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/tools', [], [], [], ['REMOTE_ADDR' => TOOL_LIST_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('returns the canonical tool entity shape', function (): void {
        $caller = createToolListCallerNode([]);
        assignToolListGatewayRole($caller);
        $node = createTestAppHostNode(['name' => 'app-1']);

        NodeTool::factory()->create([
            'name' => 'composer',
            'node_id' => $node->id,
            'expected_state' => 'installed',
            'expected_version' => '2.8',
            'config' => [
                'endpoints' => [
                    ['name' => 'composer', 'kind' => 'tcp', 'host' => 'orbit.test', 'port' => 8080]]]]);

        $response = $this->call('GET', '/api/tools', [], [], [], ['REMOTE_ADDR' => TOOL_LIST_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tools.0', [
                'name' => 'composer',
                'node' => 'app-1',
                'expected_state' => 'installed',
                'observed_state' => null,
                'version' => '2.8',
                'managed' => true,
                'endpoints' => [
                    ['name' => 'composer', 'kind' => 'tcp', 'host' => 'orbit.test', 'port' => 8080]]]);
    });

    it('returns authorization failure when the caller has no tool registry visibility', function (): void {
        createToolListCallerNode();
        $node = Node::factory()->create(['name' => 'app-1']);
        NodeTool::factory()->create(['name' => 'composer', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/tools', [], [], [], ['REMOTE_ADDR' => TOOL_LIST_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'This node is not authorized to read the tool registry.');
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/tools');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
