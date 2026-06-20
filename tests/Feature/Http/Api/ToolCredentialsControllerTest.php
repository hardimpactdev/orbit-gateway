<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const TOOL_CREDENTIALS_CALLER_WG_IP = '10.6.0.97';

function createToolCredentialsCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => TOOL_CREDENTIALS_CALLER_WG_IP,
        'wireguard_address' => TOOL_CREDENTIALS_CALLER_WG_IP,
    ], $overrides));
}

function grantToolCredentialsAccess(Node $caller, Node $target): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $target->id,
        'permissions' => json_encode(['tool:credentials']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ToolCredentialsController', function (): void {
    it('returns credentials when caller has tool:credentials grant', function (): void {
        $caller = createToolCredentialsCallerNode();
        $agentNode = Node::factory()->create([
            'name' => 'agent-1',
            'status' => 'active',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $agentNode->id,
            'role' => 'agent',
            'status' => 'active',
        ]);

        NodeTool::factory()->create([
            'node_id' => $agentNode->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
            'credentials' => [
                'fields' => [
                    'url' => 'https://openclaw.agent',
                    'username' => 'orbit',
                    'password' => 'secret',
                ],
            ],
        ]);

        grantToolCredentialsAccess($caller, $agentNode);

        $response = $this->call('GET', '/api/tools/openclaw/credentials?node=agent-1', [], [], [], ['REMOTE_ADDR' => TOOL_CREDENTIALS_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.tool', 'openclaw')
            ->assertJsonPath('success.data.credentials.node', 'agent-1')
            ->assertJsonPath('success.data.credentials.fields.url', 'https://openclaw.agent');
    });

    it('rejects caller without tool:credentials grant', function (): void {
        $caller = createToolCredentialsCallerNode();
        $agentNode = Node::factory()->create([
            'name' => 'agent-1',
            'status' => 'active',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $agentNode->id,
            'role' => 'agent',
            'status' => 'active',
        ]);

        NodeTool::factory()->create([
            'node_id' => $agentNode->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
            'credentials' => [
                'fields' => [
                    'url' => 'https://openclaw.agent',
                    'username' => 'orbit',
                    'password' => 'secret',
                ],
            ],
        ]);

        // No grant inserted - caller has no tool:credentials permission

        $response = $this->call('GET', '/api/tools/openclaw/credentials?node=agent-1', [], [], [], ['REMOTE_ADDR' => TOOL_CREDENTIALS_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('rejects agent self credential requests', function (): void {
        $agentNode = Node::factory()->create([
            'name' => 'agent-1',
            'status' => 'active',
            'host' => TOOL_CREDENTIALS_CALLER_WG_IP,
            'wireguard_address' => TOOL_CREDENTIALS_CALLER_WG_IP,
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $agentNode->id,
            'role' => 'agent',
            'status' => 'active',
        ]);

        NodeTool::factory()->create([
            'node_id' => $agentNode->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
            'credentials' => [
                'fields' => [
                    'url' => 'https://openclaw.agent',
                    'username' => 'orbit',
                    'password' => 'secret',
                ],
            ],
        ]);

        $response = $this->call('GET', '/api/tools/openclaw/credentials?node=agent-1', [], [], [], ['REMOTE_ADDR' => TOOL_CREDENTIALS_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/tools/openclaw/credentials');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
