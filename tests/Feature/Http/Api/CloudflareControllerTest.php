<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CLOUDFLARE_API_CALLER_WG_IP = '10.6.0.88';

beforeEach(function (): void {
    config()->set('orbit.cloudflare.api_token', 'test-token');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Http::allowStrayRequests();
});

function createCloudflareApiCallerNode(string $role = 'gateway'): Node
{
    $node = Node::factory()->create([
        'name' => "cf-api-{$role}",
        'host' => CLOUDFLARE_API_CALLER_WG_IP,
        'wireguard_address' => CLOUDFLARE_API_CALLER_WG_IP,
        'platform' => 'ubuntu',
        'status' => 'active',
    ]);

    if ($role === 'gateway') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
    }

    if ($role === 'app-dev') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
    }

    return $node;
}

/**
 * @param  list<string>  $permissions
 */
function grantCloudflareApiAccess(Node $consumer, Node $gateway, array $permissions): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $gateway->id,
        'permissions' => $permissions,
        'custom_permissions' => [],
    ]);
}

it('lists Cloudflare zones through the gateway API', function (): void {
    createCloudflareApiCallerNode();

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [
                [
                    'id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    'name' => 'lindaretel.nl',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $response = $this->call('GET', '/api/cloudflare/zones', [], [], [], [
        'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
    ]);

    $response->assertOk()
        ->assertJsonPath('success.data.zones.0.name', 'lindaretel.nl')
        ->assertJsonPath('success.meta.count', 1);
});

it('lists Cloudflare zones for a caller with a gateway grant', function (): void {
    $gateway = createTestGatewayNode(['name' => 'gateway-1']);
    $caller = createCloudflareApiCallerNode('control');
    grantCloudflareApiAccess($caller, $gateway, ['cf:zone:list']);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [
                [
                    'id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    'name' => 'lindaretel.nl',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    $response = $this->call('GET', '/api/cloudflare/zones', [], [], [], [
        'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
    ]);

    $response->assertOk()
        ->assertJsonPath('success.data.zones.0.name', 'lindaretel.nl')
        ->assertJsonPath('success.meta.count', 1);
});

it('denies callers without the required Cloudflare grant before provider requests', function (): void {
    $gateway = createTestGatewayNode(['name' => 'gateway-1']);
    createCloudflareApiCallerNode('app-dev');

    $response = $this->call('GET', '/api/cloudflare/zones', [], [], [], [
        'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
    ]);

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', 'cf:zone:list')
        ->assertJsonPath('error.meta.serving_node', $gateway->name);

    Http::assertNothingSent();
});

it('requires the disable permission for the Cloudflare SSL disable API route', function (): void {
    $gateway = createTestGatewayNode(['name' => 'gateway-1']);
    $caller = createCloudflareApiCallerNode('control');
    grantCloudflareApiAccess($caller, $gateway, ['cf:ssl:enable']);

    $response = $this->call('PUT', '/api/cloudflare/zones/lindaretel.nl/ssl/disable', [
        'destructive_consent' => true,
    ], [], [], [
        'REMOTE_ADDR' => CLOUDFLARE_API_CALLER_WG_IP,
    ]);

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', 'cf:ssl:disable');

    Http::assertNothingSent();
});
