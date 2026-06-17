<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const S3_CRED_JSON_CALLER_WG_IP = '10.6.1.100';

function s3CredJsonCallerNode(string $role = 'gateway'): Node
{
    $node = Node::factory()->create([
        'name' => 'json-cred-caller',
        'host' => S3_CRED_JSON_CALLER_WG_IP,
        'wireguard_address' => S3_CRED_JSON_CALLER_WG_IP,
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
    ]);

    return $node;
}

function s3CredJsonStorageNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'storage-1',
        'host' => '10.6.0.44',
        'wireguard_address' => '10.6.0.44',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 's3',
        'status' => 'active',
    ]);

    return $node;
}

function s3CredJsonRouterNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'router-1',
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'router',
        'status' => 'active',
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $credentials
 * @param  array<string, mixed>  $config
 */
function s3CredJsonSeaweedfsTool(Node $storage, array $credentials = [], array $config = []): NodeTool
{
    return NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'expected_state' => 'installed',
        'config' => array_merge([
            'backend_host' => "{$storage->name}.s3.orbit",
            'public_hosts' => [],
        ], $config),
        'credentials' => $credentials !== [] ? $credentials : [
            'fields' => [
                'access_key_id' => 'TESTACCESSKEYID12345',
                'secret_access_key' => 'test-secret-access-key-value',
                'region' => 'orbit',
                'endpoint' => 'https://s3.orbit',
                'bucket_style' => 'path',
            ],
        ],
    ]);
}

function s3CredJsonServiceRoute(Node $router): ProxyRoute
{
    return ProxyRoute::factory()->create([
        'node_id' => $router->id,
        'domain' => 's3.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => [
            'owner_name' => 'seaweedfs',
            'protocol' => 's3',
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'storage-1.s3.orbit', 'port' => 8333],
            ],
        ],
    ]);
}

// ---------------------------------------------------------------------------
// Success shape
// ---------------------------------------------------------------------------

describe('S3CredentialsJsonRenderer success shape', function (): void {
    it('returns the exact documented JSON success envelope shape', function (): void {
        s3CredJsonCallerNode();
        $storage = s3CredJsonStorageNode();
        $router = s3CredJsonRouterNode();
        s3CredJsonSeaweedfsTool($storage, credentials: [
            'fields' => [
                'access_key_id' => 'MYACCESSKEYID12345678',
                'secret_access_key' => 'my-secret-access-key-value',
                'region' => 'orbit',
                'endpoint' => 'https://s3.orbit',
                'bucket_style' => 'path',
            ],
        ], config: [
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => ['s3.example.com'],
        ]);
        s3CredJsonServiceRoute($router);

        $response = $this->get('/api/s3/credentials?node=storage-1', [
            'REMOTE_ADDR' => S3_CRED_JSON_CALLER_WG_IP,
        ]);

        $response->assertOk();
        $body = $response->json();

        expect($body)->toHaveKey('success')
            ->and($body['success'])->toHaveKey('data')
            ->and($body['success'])->toHaveKey('meta')
            ->and($body['success']['data'])->toHaveKey('credentials')
            ->and($body['success']['meta']['tool'])->toBe('seaweedfs');

        $credentials = $body['success']['data']['credentials'];

        expect($credentials['node'])->toBe('storage-1')
            ->and($credentials['private_endpoint'])->toBe('https://s3.orbit')
            ->and($credentials['public_endpoints'])->toBe(['https://s3.example.com'])
            ->and($credentials['region'])->toBe('orbit')
            ->and($credentials['access_key_id'])->toBe('MYACCESSKEYID12345678')
            ->and($credentials['secret_access_key'])->toBe('my-secret-access-key-value')
            ->and($credentials['bucket_endpoint_style'])->toBe('path')
            ->and($credentials['backend_pool'])->toBe(['http://storage-1.s3.orbit:8333']);
    });

    it('places secret_access_key after access_key_id in the credentials object', function (): void {
        s3CredJsonCallerNode();
        $storage = s3CredJsonStorageNode();
        s3CredJsonRouterNode();
        s3CredJsonSeaweedfsTool($storage);

        $response = $this->get('/api/s3/credentials?node=storage-1', [
            'REMOTE_ADDR' => S3_CRED_JSON_CALLER_WG_IP,
        ]);

        $response->assertOk();
        $json = $response->getContent();
        $akPos = strpos($json, 'access_key_id');
        $skPos = strpos($json, 'secret_access_key');

        expect($akPos)->not->toBeFalse()
            ->and($skPos)->not->toBeFalse()
            ->and($skPos)->toBeGreaterThan($akPos);
    });

    it('includes meta.tool=seaweedfs in the success envelope', function (): void {
        s3CredJsonCallerNode();
        $storage = s3CredJsonStorageNode();
        s3CredJsonRouterNode();
        s3CredJsonSeaweedfsTool($storage);

        $response = $this->get('/api/s3/credentials?node=storage-1', [
            'REMOTE_ADDR' => S3_CRED_JSON_CALLER_WG_IP,
        ]);

        $response->assertOk()
            ->assertJsonPath('success.meta.tool', 'seaweedfs');
    });
});

// ---------------------------------------------------------------------------
// Error codes
// ---------------------------------------------------------------------------

describe('S3CredentialsJsonRenderer error codes', function (): void {
    it('emits validation_failed with field=node when node is ambiguous', function (): void {
        s3CredJsonCallerNode();
        Node::factory()->create(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44', 'status' => 'active'])
            ->roleAssignments()->create(['role' => 's3', 'status' => 'active']);
        Node::factory()->create(['name' => 'storage-2', 'wireguard_address' => '10.6.0.45', 'status' => 'active'])
            ->roleAssignments()->create(['role' => 's3', 'status' => 'active']);

        $response = $this->get('/api/s3/credentials', [
            'REMOTE_ADDR' => S3_CRED_JSON_CALLER_WG_IP,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node')
            ->assertJsonPath('error.meta.required_role', 's3');
    });

    it('emits validation_failed with field=router when no router exists', function (): void {
        s3CredJsonCallerNode();
        s3CredJsonStorageNode();

        $response = $this->get('/api/s3/credentials?node=storage-1', [
            'REMOTE_ADDR' => S3_CRED_JSON_CALLER_WG_IP,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'router')
            ->assertJsonPath('error.meta.required_role', 'router');
    });

    it('emits authorization_failed when caller is not authorized', function (): void {
        s3CredJsonCallerNode(role: 'app-production');
        $storage = s3CredJsonStorageNode();
        s3CredJsonRouterNode();
        s3CredJsonSeaweedfsTool($storage);

        $response = $this->get('/api/s3/credentials?node=storage-1', [
            'REMOTE_ADDR' => S3_CRED_JSON_CALLER_WG_IP,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('emits s3.credentials_missing with next_command when credentials are missing', function (): void {
        s3CredJsonCallerNode();
        $storage = s3CredJsonStorageNode();
        s3CredJsonRouterNode();

        NodeTool::factory()->create([
            'node_id' => $storage->id,
            'name' => 'seaweedfs',
            'expected_state' => 'installed',
            'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
            'credentials' => null,
        ]);

        $response = $this->get('/api/s3/credentials?node=storage-1', [
            'REMOTE_ADDR' => S3_CRED_JSON_CALLER_WG_IP,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 's3.credentials_missing')
            ->assertJsonPath('error.message', "SeaweedFS service credentials are missing for 'storage-1'.")
            ->assertJsonPath('error.meta.node', 'storage-1')
            ->assertJsonPath('error.meta.tool', 'seaweedfs')
            ->assertJsonPath('error.meta.next_command', 'doctor --family=tool --restore --node=storage-1');
    });
});
