<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(RefreshDatabase::class);

const SHOW_CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiShowNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.7',
        'agent_ide_config' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createShowCallerNode(): void
{
    DB::table('nodes')->insert([
        'name' => 'caller',
        'host' => SHOW_CALLER_WG_IP,
        'orbit_path' => '/home/test/orbit',
        'status' => 'active',
        'wireguard_address' => SHOW_CALLER_WG_IP,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignApiShowNodeRole(string $nodeName, string $role, array $settings = []): void
{
    DB::table('node_role')->insert([
        'node_id' => DB::table('nodes')->where('name', $nodeName)->value('id'),
        'role' => $role,
        'status' => 'active',
        'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
        'last_error' => null,
        'converged_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, string>  $server
 */
function getApiNodeJson(string $uri, array $server = []): TestResponse
{
    /** @var TestCase $test */
    // @phpstan-ignore-next-line varTag.nativeType
    $test = test();

    return $test->call(
        'GET',
        $uri,
        [],
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server),
    );
}

describe('NodeShowController', function (): void {
    beforeEach(function (): void {
        createShowCallerNode();
    });

    it('returns a single node by name', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'app-1',
                'platform' => 'ubuntu_24-04',
                'status' => 'active',
            ]),
        ]);
        assignApiShowNodeRole('app-1', 'app-dev', ['tld' => 'test']);

        $response = getApiNodeJson('/api/nodes/app-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'node' => [
                            'name' => 'app-1',
                            'status' => 'active',
                            'platform' => 'ubuntu_24-04',
                            'roles' => [
                                [
                                    'role' => 'app-dev',
                                    'status' => 'active',
                                    'settings' => ['tld' => 'test'],
                                    'last_error' => null,
                                ],
                            ],
                            'addresses' => [
                                'wireguard' => '10.6.0.7',
                            ],
                            'agent_ide' => [
                                'adapter' => null,
                                'source' => 'default',
                            ],
                            'grants' => [
                                'consuming_nodes' => [],
                                'serving_nodes' => [],
                            ],
                        ],
                    ],
                ],
            ]);
    });

    it('returns gateway-coupled vpn role assignments with full payload fields', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'gateway-1',
                'host' => '10.6.0.2',
                'wireguard_address' => '10.6.0.2',
            ]),
        ]);
        assignApiShowNodeRole('gateway-1', 'gateway');
        assignApiShowNodeRole('gateway-1', 'vpn', [
            'public_endpoint' => 'vpn.example.test',
            'wireguard_cidr' => '10.44.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.44.0.1',
        ]);

        $response = getApiNodeJson('/api/nodes/gateway-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.roles.1.role', 'vpn')
            ->assertJsonPath('success.data.node.roles.1.settings.public_endpoint', 'vpn.example.test')
            ->assertJsonPath('success.data.node.roles.1.settings.wireguard_cidr', '10.44.0.0/24')
            ->assertJsonPath('success.data.node.roles.1.settings.wireguard_port', 51820)
            ->assertJsonPath('success.data.node.roles.1.settings.dns_ip', '10.44.0.1')
            ->assertJsonPath('success.data.node.roles.1.last_error', null);
    });

    it('logs activity for a successful node registry read', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'app-1',
            ]),
        ]);

        $response = getApiNodeJson('/api/nodes/app-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk();

        $entry = Activity::query()->first();

        expect($entry)->not->toBeNull();
        expect($entry->log_name)->toBe('api');
        expect($entry->event)->toBe('api:GET /nodes/{name}');
        expect($entry->subject_type)->toBe(Node::class);
        expect($entry->subject_id)->toBe(DB::table('nodes')->where('name', 'app-1')->value('id'));
        expect($entry->properties->get('type'))->toBe('read');
        expect($entry->properties->get('method'))->toBe('GET');
        expect($entry->properties->get('path'))->toBe('api/nodes/app-1');
    });

    it('returns 404 for non-existent node', function (): void {
        $response = getApiNodeJson('/api/nodes/non-existent', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJson([
                'error' => [
                    'code' => 'node.not_found',
                    'message' => "Node 'non-existent' not found or not visible.",
                    'meta' => [
                        'name' => 'non-existent',
                    ],
                ],
            ]);
    });

    it('does not serialize node environment fields', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'gateway-1',
            ]),
        ]);
        assignApiShowNodeRole('gateway-1', 'gateway');

        $response = getApiNodeJson('/api/nodes/gateway-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk();

        expect($response->json('success.data.node'))->not->toHaveKey('environment');
    });

    it('keeps app role environment out of node serialization', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'control-app',
            ]),
        ]);
        assignApiShowNodeRole('control-app', 'app-prod');

        $response = getApiNodeJson('/api/nodes/control-app', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk();

        expect($response->json('success.data.node'))->not->toHaveKey('environment');
    });

    it('defaults platform to unknown when not set', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'app-1',
                'platform' => null,
            ]),
        ]);

        $response = getApiNodeJson('/api/nodes/app-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.platform', 'unknown');
    });

    it('does not expose host as the WireGuard address when wireguard_address is missing', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'app-1',
                'wireguard_address' => null,
                'host' => '192.168.1.1',
            ]),
        ]);

        $response = getApiNodeJson('/api/nodes/app-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.addresses.wireguard', null);
    });

    it('returns correct node shape for gateway node', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'gateway-1',
                'platform' => 'ubuntu_24-04',
                'status' => 'active',
                'wireguard_address' => '10.6.0.2',
            ]),
        ]);
        assignApiShowNodeRole('gateway-1', 'gateway');

        $response = getApiNodeJson('/api/nodes/gateway-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJson([
                'success' => [
                    'data' => [
                        'node' => [
                            'name' => 'gateway-1',
                            'status' => 'active',
                            'platform' => 'ubuntu_24-04',
                            'roles' => [
                                [
                                    'role' => 'gateway',
                                    'status' => 'active',
                                    'settings' => [],
                                    'last_error' => null,
                                ],
                            ],
                            'addresses' => [
                                'wireguard' => '10.6.0.2',
                            ],
                            'agent_ide' => [
                                'adapter' => null,
                                'source' => 'default',
                            ],
                            'grants' => [
                                'consuming_nodes' => [],
                                'serving_nodes' => [],
                            ],
                        ],
                    ],
                ],
            ]);
    });

    it('returns explicit node agent IDE defaults', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'agent_ide_config' => json_encode(['adapter' => 'polyscope'], JSON_THROW_ON_ERROR),
            ]),
        ]);

        $response = getApiNodeJson('/api/nodes/app-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.agent_ide.adapter', 'polyscope')
            ->assertJsonPath('success.data.node.agent_ide.source', 'node');
    });

    it('returns real grants data', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'app-1',
            ]),
            apiShowNodeRow([
                'name' => 'control-1',
            ]),
            apiShowNodeRow([
                'name' => 'control-2',
            ]),
        ]);

        $app1Id = DB::table('nodes')->where('name', 'app-1')->value('id');
        $control1Id = DB::table('nodes')->where('name', 'control-1')->value('id');
        $control2Id = DB::table('nodes')->where('name', 'control-2')->value('id');

        DB::table('node_access')->insert([
            [
                'consumer_node_id' => $control1Id,
                'serving_node_id' => $app1Id,
                'permissions' => json_encode(['node:read'], JSON_THROW_ON_ERROR),
                'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'consumer_node_id' => $control2Id,
                'serving_node_id' => $app1Id,
                'permissions' => json_encode(['tool:read'], JSON_THROW_ON_ERROR),
                'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'consumer_node_id' => $app1Id,
                'serving_node_id' => $control1Id,
                'permissions' => json_encode(['app:read'], JSON_THROW_ON_ERROR),
                'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = getApiNodeJson('/api/nodes/app-1', ['REMOTE_ADDR' => SHOW_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.node.grants.consuming_nodes', [
                ['name' => 'control-1', 'permissions' => ['node:read']],
                ['name' => 'control-2', 'permissions' => ['tool:read']],
            ])
            ->assertJsonPath('success.data.node.grants.serving_nodes', [
                ['name' => 'control-1', 'permissions' => ['app:read']],
            ]);
    });

    it('rejects unauthenticated requests', function (): void {
        DB::table('nodes')->insert([
            apiShowNodeRow([
                'name' => 'existing-app',
            ]),
        ]);

        $response = getApiNodeJson('/api/nodes/existing-app');

        $response->assertForbidden()
            ->assertJson([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ]);
    });
});
