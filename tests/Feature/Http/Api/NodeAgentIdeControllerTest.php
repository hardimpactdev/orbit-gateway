<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const AGENT_IDE_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiNodeAgentIdeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.7',
        'public_ipv4' => null,
        'public_ipv6' => null,
        'agent_ide_config' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createAgentIdeCallerNode(?string $role = null): int
{
    $name = $role === null ? 'control-caller' : "{$role}-caller";

    $nodeId = (int) DB::table('nodes')->insertGetId(apiNodeAgentIdeRow([
        'name' => $name,
        'host' => AGENT_IDE_CALLER_WG_IP,
        'wireguard_address' => AGENT_IDE_CALLER_WG_IP,
    ]));

    if ($role !== null) {
        assignAgentIdeNodeRole($nodeId, $role);
    }

    return $nodeId;
}

function assignAgentIdeNodeRole(int $nodeId, string $role): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => 'active',
    ]);
}

/**
 * @param  list<string>  $permissions
 */
function grantAgentIdeNodeAccess(int $consumerId, int $servingId, array $permissions): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $consumerId,
        'serving_node_id' => $servingId,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function postNodeAgentIdeJson(string $uri, array $data, array $server = []): TestResponse
{
    return test()->call(
        'POST',
        $uri,
        $data,
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server),
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

describe('NodeAgentIdeController', function (): void {
    it('sets a node agent IDE default for an authorized control caller', function (): void {
        $callerId = createAgentIdeCallerNode();
        $targetId = (int) DB::table('nodes')->insertGetId(apiNodeAgentIdeRow());
        grantAgentIdeNodeAccess($callerId, $targetId, ['node:agent']);

        $response = postNodeAgentIdeJson('/api/nodes/app-1/agent-ide', [
            'agent_ide' => 'opencode',
        ], ['REMOTE_ADDR' => AGENT_IDE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'name' => 'app-1',
                        'agent_ide' => [
                            'adapter' => 'opencode',
                            'source' => 'node',
                        ],
                        'action' => 'set',
                    ],
                ],
            ]);

        $config = json_decode((string) DB::table('nodes')->where('name', 'app-1')->value('agent_ide_config'), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($config)->toBe(['adapter' => 'opencode']);
    });

    it('clears a node agent IDE default with none', function (): void {
        createAgentIdeCallerNode('gateway');
        DB::table('nodes')->insert(apiNodeAgentIdeRow([
            'agent_ide_config' => json_encode(['adapter' => 'opencode'], JSON_THROW_ON_ERROR),
        ]));

        $response = postNodeAgentIdeJson('/api/nodes/app-1/agent-ide', [
            'agent_ide' => 'none',
        ], ['REMOTE_ADDR' => AGENT_IDE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.agent_ide.adapter', null)
            ->assertJsonPath('success.data.agent_ide.source', 'default')
            ->assertJsonPath('success.data.action', 'set');

        expect(DB::table('nodes')->where('name', 'app-1')->value('agent_ide_config'))->toBeNull();
    });

    it('returns converged when the requested adapter already matches', function (): void {
        createAgentIdeCallerNode('gateway');
        DB::table('nodes')->insert(apiNodeAgentIdeRow([
            'agent_ide_config' => json_encode(['adapter' => 'polyscope'], JSON_THROW_ON_ERROR),
        ]));

        $response = postNodeAgentIdeJson('/api/nodes/app-1/agent-ide', [
            'agent_ide' => 'polyscope',
        ], ['REMOTE_ADDR' => AGENT_IDE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.action', 'converged')
            ->assertJsonPath('success.data.agent_ide.adapter', 'polyscope');
    });

    it('logs activity for a successful node agent IDE write', function (): void {
        $callerId = createAgentIdeCallerNode();
        $targetId = (int) DB::table('nodes')->insertGetId(apiNodeAgentIdeRow());
        grantAgentIdeNodeAccess($callerId, $targetId, ['node:agent']);

        $response = postNodeAgentIdeJson('/api/nodes/app-1/agent-ide', [
            'agent_ide' => 'opencode',
        ], ['REMOTE_ADDR' => AGENT_IDE_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->event)->toBe('api:POST /nodes/{name}/agent-ide');
        expect($entry->subject_type)->toBe(Node::class);
        expect($entry->subject_id)->toBe($targetId);
        expect($entry->description)->toBe('Node app-1 agent IDE set to opencode');
        expect($entry->properties->get('type'))->toBe('write');
        expect($entry->properties->get('target_node'))->toBe('app-1');
        expect($entry->properties->get('agent_ide'))->toBe('opencode');
        expect($entry->properties->get('action'))->toBe('set');
    });

    it('sets node agent IDE defaults for app callers with explicit grants', function (): void {
        $callerId = createAgentIdeCallerNode('app-dev');
        $targetId = (int) DB::table('nodes')->insertGetId(apiNodeAgentIdeRow());
        grantAgentIdeNodeAccess($callerId, $targetId, ['node:agent']);

        $response = postNodeAgentIdeJson('/api/nodes/app-1/agent-ide', [
            'agent_ide' => 'opencode',
        ], ['REMOTE_ADDR' => AGENT_IDE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.agent_ide.adapter', 'opencode');

        expect(DB::table('nodes')->where('name', 'app-1')->value('agent_ide_config'))->not->toBeNull();
    });

    it('rejects callers without node agent grants', function (): void {
        createAgentIdeCallerNode();
        DB::table('nodes')->insert(apiNodeAgentIdeRow());

        $response = postNodeAgentIdeJson('/api/nodes/app-1/agent-ide', [
            'agent_ide' => 'opencode',
        ], ['REMOTE_ADDR' => AGENT_IDE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', "This node is not authorized for 'node:agent' on 'app-1'.")
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'node:agent')
            ->assertJsonPath('error.meta.serving_node', 'app-1');
    });

    it('returns validation errors for missing and unsupported adapters', function (array $data, string $code, string $message, string $field): void {
        createAgentIdeCallerNode('gateway');
        DB::table('nodes')->insert(apiNodeAgentIdeRow());

        $response = postNodeAgentIdeJson('/api/nodes/app-1/agent-ide', $data, ['REMOTE_ADDR' => AGENT_IDE_CALLER_WG_IP]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', $code)
            ->assertJsonPath('error.message', $message)
            ->assertJsonPath("error.meta.{$field}", $data['agent_ide'] ?? 'agent_ide');
    })->with([
        'missing adapter' => [[], 'validation_failed', 'Agent IDE adapter is required.', 'field'],
        'unsupported adapter' => [['agent_ide' => 'unknown-ide'], 'node.unsupported_adapter', "Adapter 'unknown-ide' is not supported.", 'adapter'],
    ]);

    it('returns not found for missing nodes', function (): void {
        createAgentIdeCallerNode('gateway');

        $response = postNodeAgentIdeJson('/api/nodes/missing-node/agent-ide', [
            'agent_ide' => 'opencode',
        ], ['REMOTE_ADDR' => AGENT_IDE_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'node.not_found')
            ->assertJsonPath('error.message', "Node 'missing-node' not found.")
            ->assertJsonPath('error.meta.name', 'missing-node');
    });
});
