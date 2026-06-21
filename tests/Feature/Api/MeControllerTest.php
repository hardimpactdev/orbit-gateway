<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\call;
use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function meNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'peer-1',
        'host' => '10.6.0.8',
        'wireguard_address' => '10.6.0.8',
        'orbit_path' => '/Users/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'macos_15-4',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $settings
 */
function assignMeNodeRole(int $nodeId, string $role, array $settings = []): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

function assignMeGatewayRole(int $nodeId): void
{
    assignMeNodeRole($nodeId, 'gateway');
}

describe('GET /api/me', function (): void {
    it('returns 403 for unknown peer', function (): void {
        $response = getJson('/api/me');

        $response->assertForbidden()
            ->assertExactJson([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ]);
    });

    it('returns success shape for peer node via wireguard ip', function (): void {
        DB::table('nodes')->insert(meNodeRow());
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'platform' => 'ubuntu_24-04',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertExactJson([
                'success' => [
                    'data' => [
                        'self' => [
                            'name' => 'peer-1',
                            'status' => 'active',
                            'platform' => 'macos_15-4',
                            'roles' => [],
                            'addresses' => [
                                'wireguard' => '10.6.0.8',
                            ],
                        ],
                        'gateway' => [
                            'name' => 'gateway-1',
                            'status' => 'active',
                            'platform' => 'ubuntu_24-04',
                            'roles' => [
                                [
                                    'role' => 'gateway',
                                    'status' => 'active',
                                    'settings' => [],
                                ],
                            ],
                            'addresses' => [
                                'wireguard' => '10.6.0.2',
                            ],
                        ],
                    ],
                ],
            ]);
    });

    it('returns success shape for gateway-local node via wireguard ip', function (): void {
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'platform' => 'ubuntu_24-04',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.2']);

        $response->assertOk()
            ->assertExactJson([
                'success' => [
                    'data' => [
                        'self' => [
                            'name' => 'gateway-1',
                            'status' => 'active',
                            'platform' => 'ubuntu_24-04',
                            'roles' => [
                                [
                                    'role' => 'gateway',
                                    'status' => 'active',
                                    'settings' => [],
                                ],
                            ],
                            'addresses' => [
                                'wireguard' => '10.6.0.2',
                            ],
                        ],
                        'gateway' => [
                            'name' => 'gateway-1',
                            'status' => 'active',
                            'platform' => 'ubuntu_24-04',
                            'roles' => [
                                [
                                    'role' => 'gateway',
                                    'status' => 'active',
                                    'settings' => [],
                                ],
                            ],
                            'addresses' => [
                                'wireguard' => '10.6.0.2',
                            ],
                        ],
                    ],
                ],
            ]);
    });

    it('defaults platform to unknown and status to active when null', function (): void {
        DB::table('nodes')->insert(array_merge(meNodeRow(), [
            'platform' => null,
        ]));
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'platform' => 'ubuntu_24-04',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertJsonPath('success.data.self.platform', 'unknown')
            ->assertJsonPath('success.data.self.status', 'active');
    });

    it('serializes active app role assignments without node environment output', function (): void {
        $appId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.9',
        ]));
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));
        assignMeGatewayRole($gatewayId);
        assignMeNodeRole($appId, 'app-dev', ['tld' => 'test']);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.9']);

        $response->assertOk()
            ->assertJsonPath('success.data.self.roles.0.role', 'app-dev')
            ->assertJsonMissingPath('success.data.self.environment')
            ->assertJsonMissingPath('success.data.gateway.environment');
    });

    it('does not emit node environment without an active app role assignment', function (): void {
        DB::table('nodes')->insert(meNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.9',
        ]));
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.9']);

        $response->assertOk()
            ->assertJsonMissingPath('success.data.self.environment')
            ->assertJsonMissingPath('success.data.gateway.environment');
    });

    it('serializes composable roles for self and gateway', function (): void {
        $self = Node::factory()->create([
            'name' => 'peer-1',
            'host' => '10.6.0.8',
            'wireguard_address' => '10.6.0.8',
            'orbit_path' => '/Users/nckrtl/orbit',
            'status' => 'active',
            'platform' => 'macos_15-4',
        ]);

        $gateway = Node::factory()->create([
            'name' => 'gateway-1',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $self->id,
            'role' => 'database',
            'status' => 'error',
            'settings' => [],
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'gateway',
            'status' => 'active',
            'settings' => [],
        ]);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertJsonPath('success.data.self.roles', [
                [
                    'role' => 'database',
                    'status' => 'error',
                    'settings' => [],
                ],
            ])
            ->assertJsonPath('success.data.gateway.roles', [
                [
                    'role' => 'gateway',
                    'status' => 'active',
                    'settings' => [],
                ],
            ]);

        expect($response->getContent())->toContain('"settings":{}');
    });

    it('resolves the gateway from an active gateway role assignment', function (): void {
        DB::table('nodes')->insert(meNodeRow());

        $gateway = Node::factory()->create([
            'name' => 'gateway-1',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'orbit_path' => '/home/orbit/orbit',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
        ]);

        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'gateway',
            'status' => 'active',
            'settings' => [],
        ]);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk()
            ->assertJsonPath('success.data.gateway.name', 'gateway-1')
            ->assertJsonPath('success.data.gateway.roles.0.role', 'gateway');
    });

    it('does not include legacy id field', function (): void {
        DB::table('nodes')->insert(meNodeRow());
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.6.0.8']);

        $response->assertOk();

        $json = $response->json();
        expect($json['success']['data']['self'])->not->toHaveKey('id')
            ->and($json['success']['data']['gateway'])->not->toHaveKey('id');
    });

    it('authenticates scheduler clients by wireguard address instead of client headers', function (): void {
        $appId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.9',
        ]));
        assignMeNodeRole($appId, 'app-dev', ['tld' => 'test']);
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call(
            'GET',
            '/api/me',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '10.6.0.9',
                'HTTP_X_ORBIT_CLIENT' => 'scheduler',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.self.name', 'app-1')
            ->assertJsonPath('success.data.self.roles.0.role', 'app-dev')
            ->assertJsonMissingPath('success.data.self.role');
    });

    it('rejects spoofed scheduler client headers without a known wireguard peer', function (): void {
        DB::table('nodes')->insert(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));

        $response = call(
            'GET',
            '/api/me',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '10.6.0.99',
                'HTTP_X_ORBIT_CLIENT' => 'scheduler',
            ],
        );

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('trusts the orbit-caddy supplied wireguard peer header only when gateway container proxy headers are enabled', function (): void {
        config(['orbit.trust_wireguard_proxy_header' => true]);

        DB::table('nodes')->insert(meNodeRow());
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'platform' => 'ubuntu_24-04',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call(
            'GET',
            '/api/me',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '172.18.0.3',
                'HTTP_X_ORBIT_WIREGUARD_IP' => '10.6.0.8',
            ],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.self.name', 'peer-1');
    });

    it('rejects the orbit-caddy wireguard peer header when gateway container proxy headers are disabled', function (): void {
        config(['orbit.trust_wireguard_proxy_header' => false]);

        DB::table('nodes')->insert(meNodeRow());

        $response = call(
            'GET',
            '/api/me',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '172.18.0.3',
                'HTTP_X_ORBIT_WIREGUARD_IP' => '10.6.0.8',
            ],
        );

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('canonicalizes docker e2e raw subnet peers only when the docker provider shim is enabled', function (): void {
        config([
            'orbit.e2e_topology_provider' => 'docker',
            'orbit.e2e_trust_wireguard_header' => true,
        ]);

        DB::table('nodes')->insert(meNodeRow([
            'wireguard_address' => '10.6.0.3',
        ]));
        $gatewayId = (int) DB::table('nodes')->insertGetId(meNodeRow([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]));
        assignMeGatewayRole($gatewayId);

        $response = call('GET', '/api/me', [], [], [], ['REMOTE_ADDR' => '10.61.0.3']);

        $response->assertOk()
            ->assertJsonPath('success.data.self.name', 'peer-1')
            ->assertJsonPath('success.data.self.addresses.wireguard', '10.6.0.3');
    });
});
