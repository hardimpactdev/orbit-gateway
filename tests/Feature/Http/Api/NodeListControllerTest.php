<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

const CALLER_WG_IP = '10.6.0.99';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiNodeRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'tld' => 'test',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.7',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function createCallerNode(): void
{
    DB::table('nodes')->insert([
        'name' => 'caller',
        'host' => CALLER_WG_IP,
        'orbit_path' => '/home/test/orbit',
        'status' => 'active',
        'wireguard_address' => CALLER_WG_IP,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function assignApiNodeRole(string $nodeName, string $role, array $settings = []): void
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
function getApiNodesJson(string $uri, array $server = []): TestResponse
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

describe('NodeListController', function (): void {
    beforeEach(function (): void {
        bindDevelopmentDnsMappingTestDoubles('node-list-controller-dns');
        app()->instance(RemoteShell::class, new NodeListControllerRemoteShell);

        createCallerNode();
    });

    it('lists all active nodes sorted by effective role assignment then name', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow(['name' => 'zebra-app']),
            apiNodeRow(['name' => 'alpha-app']),
            apiNodeRow(['name' => 'database-1']),
            apiNodeRow(['name' => 'gateway-1']),
            apiNodeRow(['name' => 'control-1']),
        ]);
        assignApiNodeRole('zebra-app', 'app-dev', ['tld' => 'test']);
        assignApiNodeRole('alpha-app', 'app-dev', ['tld' => 'test']);
        assignApiNodeRole('database-1', 'database');
        assignApiNodeRole('gateway-1', 'gateway');

        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk();
        $nodes = $response->json('success.data.nodes');
        $names = array_column($nodes, 'name');
        expect($names)->toBe(['alpha-app', 'zebra-app', 'database-1', 'gateway-1', 'caller', 'control-1']);
    });

    it('returns only caller node when no other nodes exist', function (): void {
        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.nodes.0.name', 'caller');
    });

    it('filters nodes by role', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow(['name' => 'app-1']),
            apiNodeRow(['name' => 'gateway-1']),
            apiNodeRow(['name' => 'control-1']),
            apiNodeRow(['name' => 'metrics-1']),
        ]);
        assignApiNodeRole('app-1', 'app-dev', ['tld' => 'test']);
        assignApiNodeRole('metrics-1', 'metrics');

        $response = getApiNodesJson('/api/nodes?role=app-dev', ['REMOTE_ADDR' => CALLER_WG_IP]);
        $metricsResponse = getApiNodesJson('/api/nodes?role=metrics', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonCount(1, 'success.data.nodes')
            ->assertJsonPath('success.data.nodes.0.name', 'app-1');

        $metricsResponse->assertOk()
            ->assertJsonCount(1, 'success.data.nodes')
            ->assertJsonPath('success.data.nodes.0.name', 'metrics-1');
    });

    it('filters nodes by concrete role assignments', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow(['name' => 'db-1']),
            apiNodeRow(['name' => 'plain-node']),
            apiNodeRow(['name' => 'assigned-gateway']),
            apiNodeRow(['name' => 'gateway-vpn']),
        ]);
        assignApiNodeRole('db-1', 'database');
        assignApiNodeRole('assigned-gateway', 'gateway');
        assignApiNodeRole('gateway-vpn', 'gateway');
        assignApiNodeRole('gateway-vpn', 'vpn', [
            'public_endpoint' => 'vpn.example.test',
            'wireguard_cidr' => '10.44.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.44.0.1',
        ]);

        $databaseResponse = getApiNodesJson('/api/nodes?role=database', ['REMOTE_ADDR' => CALLER_WG_IP]);
        $gatewayResponse = getApiNodesJson('/api/nodes?role=gateway', ['REMOTE_ADDR' => CALLER_WG_IP]);
        $vpnResponse = getApiNodesJson('/api/nodes?role=vpn', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $databaseResponse->assertOk()
            ->assertJsonCount(1, 'success.data.nodes')
            ->assertJsonPath('success.data.nodes.0.name', 'db-1');

        $gatewayResponse->assertOk()
            ->assertJsonCount(2, 'success.data.nodes');

        expect(array_column($gatewayResponse->json('success.data.nodes'), 'name'))
            ->toBe(['assigned-gateway', 'gateway-vpn']);

        $vpnResponse->assertOk()
            ->assertJsonCount(1, 'success.data.nodes')
            ->assertJsonPath('success.data.nodes.0.name', 'gateway-vpn');
    });

    it('rejects node environment filters', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow(['name' => 'dev-app']),
            apiNodeRow(['name' => 'prod-app']),
        ]);
        assignApiNodeRole('dev-app', 'app-dev', ['tld' => 'test']);
        assignApiNodeRole('prod-app', 'app-prod');

        $response = getApiNodesJson('/api/nodes?environment=production', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'environment')
            ->assertJsonPath('error.meta.reason', 'unsupported_field');
    });

    it('returns validation error for invalid role', function (): void {
        $response = getApiNodesJson('/api/nodes?role=invalid', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => "Invalid value for role: 'invalid'. Allowed values: gateway, vpn, router, app-dev, app-prod, database, agent, ingress, websocket, s3, metrics.",
                    'meta' => [
                        'field' => 'role',
                        'value' => 'invalid',
                        'allowed' => ['gateway', 'vpn', 'router', 'app-dev', 'app-prod', 'database', 'agent', 'ingress', 'websocket', 's3', 'metrics'],
                    ],
                ],
            ]);
    });

    it('returns validation error for invalid environment', function (): void {
        $response = getApiNodesJson('/api/nodes?environment=invalid', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Node environment filters are not supported. Filter by role instead.',
                    'meta' => [
                        'field' => 'environment',
                        'reason' => 'unsupported_field',
                    ],
                ],
            ]);
    });

    it('does not serialize node environment fields', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow([
                'name' => 'gateway-1',
                'platform' => 'ubuntu_24-04',
            ]),
        ]);
        assignApiNodeRole('gateway-1', 'gateway');

        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $gatewayNode = collect($response->json('success.data.nodes'))
            ->first(fn (array $node): bool => $node['name'] === 'gateway-1');

        expect($gatewayNode)->not->toHaveKey('environment');
    });

    it('defaults platform to unknown when not set', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow([
                'name' => 'app-1',
                'platform' => null,
            ]),
        ]);

        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $appNode = collect($response->json('success.data.nodes'))
            ->first(fn (array $node): bool => $node['name'] === 'app-1');

        expect($appNode['platform'])->toBe('unknown');
    });

    it('returns correct node field shape', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow([
                'name' => 'app-1',
                'platform' => 'ubuntu_24-04',
                'status' => 'active',
            ]),
        ]);
        assignApiNodeRole('app-1', 'app-dev', ['tld' => 'test']);

        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $appNode = collect($response->json('success.data.nodes'))
            ->first(fn (array $node): bool => $node['name'] === 'app-1');

        expect($appNode)->toBe([
            'name' => 'app-1',
            'host' => '10.6.0.7',
            'addresses' => [
                'wireguard' => '10.6.0.7',
            ],
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
            'roles' => [
                [
                    'role' => 'app-dev',
                    'status' => 'active',
                    'settings' => ['tld' => 'test'],
                    'last_error' => null,
                    'converged_at' => NodeRoleAssignment::query()
                        ->where('role', 'app-dev')
                        ->where('node_id', DB::table('nodes')->where('name', 'app-1')->value('id'))
                        ->first()
                        ?->converged_at
                        ?->toJSON(),
                ],
            ],
        ]);
    });

    it('returns gateway-coupled vpn role assignments in list output', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow([
                'name' => 'gateway-1',
                'host' => '10.6.0.2',
                'wireguard_address' => '10.6.0.2',
            ]),
        ]);

        assignApiNodeRole('gateway-1', 'gateway');
        assignApiNodeRole('gateway-1', 'vpn', [
            'public_endpoint' => 'vpn.example.test',
            'wireguard_cidr' => '10.44.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.44.0.1',
        ]);

        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $gatewayNode = collect($response->json('success.data.nodes'))
            ->first(fn (array $node): bool => $node['name'] === 'gateway-1');

        expect($gatewayNode)->not->toHaveKey('role')
            ->and($gatewayNode['roles'])->toHaveCount(2)
            ->and($gatewayNode['roles'][1])->toMatchArray([
                'role' => 'vpn',
                'status' => 'active',
                'settings' => [
                    'public_endpoint' => 'vpn.example.test',
                    'wireguard_cidr' => '10.44.0.0/24',
                    'wireguard_port' => 51820,
                    'dns_ip' => '10.44.0.1',
                ],
                'last_error' => null,
            ]);
    });

    it('serializes WireGuard peer address separately from public host metadata', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow([
                'name' => 'prod-app',
                'host' => '203.0.113.10',
                'wireguard_address' => '10.6.0.13',
            ]),
        ]);
        assignApiNodeRole('prod-app', 'app-prod');

        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $node = collect($response->json('success.data.nodes'))
            ->first(fn (array $node): bool => $node['name'] === 'prod-app');

        expect($node['host'])->toBe('203.0.113.10')
            ->and($node['addresses']['wireguard'])->toBe('10.6.0.13');
    });

    it('keeps app role environment out of node serialization', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow([
                'name' => 'control-app',
            ]),
            apiNodeRow([
                'name' => 'plain-app',
            ]),
        ]);
        assignApiNodeRole('control-app', 'app-dev', ['tld' => 'test']);

        $response = getApiNodesJson('/api/nodes', ['REMOTE_ADDR' => CALLER_WG_IP]);
        $nodes = collect($response->json('success.data.nodes'))->keyBy('name');

        expect($nodes['control-app'])->not->toHaveKey('environment')
            ->and($nodes['plain-app'])->not->toHaveKey('environment');
    });

    it('rejects unauthenticated requests', function (): void {
        $response = getApiNodesJson('/api/nodes');

        $response->assertForbidden()
            ->assertJson([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ]);
    });

    it('attaches doctor meta when doctor query is present', function (): void {
        DB::table('nodes')->insert([
            apiNodeRow([
                'name' => 'incomplete-app',
                'wireguard_address' => null,
            ]),
        ]);
        assignApiNodeRole('incomplete-app', 'app-dev', ['tld' => 'test']);
        markNodeSecurityBaselineClean(Node::query()->where('name', 'incomplete-app')->firstOrFail());

        $response = getApiNodesJson('/api/nodes?doctor=1&role=app-dev', ['REMOTE_ADDR' => CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.meta.doctor.checked', 1)
            ->assertJsonPath('success.meta.doctor.issues', 2);

        $failure = collect($response->json('success.meta.doctor.failures'))
            ->first(fn (array $failure): bool => $failure['code'] === 'node.record_incomplete');

        expect($failure)->toMatchArray([
            'node' => 'incomplete-app',
            'family' => 'node',
        ]);
    });
});

final class NodeListControllerRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
