<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const TOOL_SHOW_CALLER_WG_IP = '10.6.0.96';

function createToolShowCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => TOOL_SHOW_CALLER_WG_IP,
        'wireguard_address' => TOOL_SHOW_CALLER_WG_IP], $overrides));
}

function grantToolShowAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now()]);
}

describe('ToolShowController', function (): void {
    it('returns tool registry details by tool and node', function (): void {
        $caller = createToolShowCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantToolShowAccess($caller, $node);

        NodeTool::factory()->create([
            'name' => 'composer',
            'node_id' => $node->id,
            'expected_state' => 'installed',
            'expected_version' => '2.8']);

        $response = $this->call('GET', '/api/tools/composer?node=app-1', [], [], [], ['REMOTE_ADDR' => TOOL_SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'composer')
            ->assertJsonPath('success.data.tool.node', 'app-1')
            ->assertJsonPath('success.data.tool.expected_state', 'installed')
            ->assertJsonPath('success.data.tool.observed_state', null)
            ->assertJsonPath('success.data.tool.version', '2.8')
            ->assertJsonPath('success.data.tool.managed', true);
    });

    it('resolves the target node from an app selector', function (): void {
        $caller = createToolShowCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantToolShowAccess($caller, $node);

        App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => 'docs.example.com']);
        NodeTool::factory()->create(['name' => 'php', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/tools/php?app=docs', [], [], [], ['REMOTE_ADDR' => TOOL_SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.tool.name', 'php')
            ->assertJsonPath('success.data.tool.node', 'app-1');
    });

    it('requires a selector even when exactly one app node is visible', function (): void {
        $caller = createToolShowCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantToolShowAccess($caller, $node);

        NodeTool::factory()->create(['name' => 'caddy', 'node_id' => $node->id]);

        $response = $this->call('GET', '/api/tools/caddy', [], [], [], ['REMOTE_ADDR' => TOOL_SHOW_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'target');
    });

    it('requires a selector when more than one app node is visible', function (): void {
        $caller = createToolShowCallerNode();
        $firstNode = createTestAppHostNode(['name' => 'app-1']);
        $secondNode = createTestAppHostNode(['name' => 'app-2']);
        grantToolShowAccess($caller, $firstNode);
        grantToolShowAccess($caller, $secondNode);

        $response = $this->call('GET', '/api/tools/composer', [], [], [], ['REMOTE_ADDR' => TOOL_SHOW_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'target');
    });

    it('returns not found when the selected node has no matching tool row', function (): void {
        $caller = createToolShowCallerNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        grantToolShowAccess($caller, $node);

        $response = $this->call('GET', '/api/tools/composer?node=app-1', [], [], [], ['REMOTE_ADDR' => TOOL_SHOW_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'tool.not_found')
            ->assertJsonPath('error.meta.tool', 'composer')
            ->assertJsonPath('error.meta.node', 'app-1');
    });

    it('rejects unsupported tool catalog slugs', function (): void {
        createToolShowCallerNode([]);

        $response = $this->call('GET', '/api/tools/not-a-tool?node=app-1', [], [], [], ['REMOTE_ADDR' => TOOL_SHOW_CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'tool.unsupported_action')
            ->assertJsonPath('error.meta.tool', 'not-a-tool');
    });

    it('rejects hidden node selectors', function (): void {
        createToolShowCallerNode();
        Node::factory()->create(['name' => 'hidden']);

        $response = $this->call('GET', '/api/tools/composer?node=hidden', [], [], [], ['REMOTE_ADDR' => TOOL_SHOW_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/tools/composer');

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });
});
