<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const S3_CRED_CMD_CALLER_WG_IP = '10.6.1.98';

/**
 * @param  array<string, mixed>  $overrides
 */
function s3CredCmdCallerNode(array $overrides = [], string $role = 'gateway'): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => S3_CRED_CMD_CALLER_WG_IP,
        'wireguard_address' => S3_CRED_CMD_CALLER_WG_IP,
        'status' => 'active',
    ], $overrides));

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function s3CredCmdStorageNode(array $overrides = []): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'storage-1',
        'host' => '10.6.0.44',
        'wireguard_address' => '10.6.0.44',
        'status' => 'active',
    ], $overrides));

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 's3',
        'status' => 'active',
    ]);

    return $node;
}

function s3CredCmdRouterNode(): Node
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
function s3CredCmdSeaweedfsTool(Node $storage, array $credentials = [], array $config = []): NodeTool
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

function s3CredCmdServiceRoute(Node $router): ProxyRoute
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

/**
 * GET the s3 credentials endpoint.
 *
 * @param  array<string, mixed>  $query
 */
function s3CredCmdGet(object $test, array $query = []): TestResponse
{
    $queryString = $query !== [] ? '?'.http_build_query($query) : '';

    return $test->get('/api/s3/credentials'.$queryString, [
        'REMOTE_ADDR' => S3_CRED_CMD_CALLER_WG_IP,
    ]);
}

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

describe('S3Credentials authorization', function (): void {
    it('rejects unauthenticated callers', function (): void {
        $response = $this->get('/api/s3/credentials', [
            'REMOTE_ADDR' => '192.168.99.99',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('allows gateway-role callers without explicit tool:credentials grant', function (): void {
        $caller = s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();
        s3CredCmdSeaweedfsTool($storage);

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.node', 'storage-1');
    });

    it('denies non-gateway callers without tool:credentials grant', function (): void {
        $caller = s3CredCmdCallerNode(role: 'app-production');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();
        s3CredCmdSeaweedfsTool($storage);

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('allows non-gateway callers with an explicit tool:credentials grant', function (): void {
        $caller = s3CredCmdCallerNode(role: 'app-production');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();
        s3CredCmdSeaweedfsTool($storage);

        DB::table('node_access')->insert([
            'consumer_node_id' => $caller->id,
            'serving_node_id' => $storage->id,
            'permissions' => json_encode(['tool:credentials']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.node', 'storage-1');
    });
});

// ---------------------------------------------------------------------------
// Prerequisites
// ---------------------------------------------------------------------------

describe('S3Credentials prerequisites', function (): void {
    it('fails when no active s3 node exists', function (): void {
        s3CredCmdCallerNode(role: 'gateway');

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node')
            ->assertJsonPath('error.meta.required_role', 's3');
    });

    it('fails when no active router exists', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        s3CredCmdStorageNode();

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'router')
            ->assertJsonPath('error.meta.required_role', 'router');
    });

    it('auto-resolves node when exactly one active s3 node exists', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();
        s3CredCmdSeaweedfsTool($storage);

        $response = s3CredCmdGet($this);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.node', 'storage-1');
    });

    it('fails when node is missing and multiple s3 nodes exist', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        s3CredCmdStorageNode(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
        s3CredCmdStorageNode(['name' => 'storage-2', 'wireguard_address' => '10.6.0.45']);
        s3CredCmdRouterNode();

        $response = s3CredCmdGet($this);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node')
            ->assertJsonPath('error.meta.required_role', 's3');
    });
});

// ---------------------------------------------------------------------------
// Credential payload shape
// ---------------------------------------------------------------------------

describe('S3Credentials payload shape', function (): void {
    it('returns the full documented credential shape', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        $router = s3CredCmdRouterNode();
        s3CredCmdSeaweedfsTool($storage, credentials: [
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
        s3CredCmdServiceRoute($router);

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.node', 'storage-1')
            ->assertJsonPath('success.data.credentials.private_endpoint', 'https://s3.orbit')
            ->assertJsonPath('success.data.credentials.public_endpoints', ['https://s3.example.com'])
            ->assertJsonPath('success.data.credentials.region', 'orbit')
            ->assertJsonPath('success.data.credentials.access_key_id', 'MYACCESSKEYID12345678')
            ->assertJsonPath('success.data.credentials.secret_access_key', 'my-secret-access-key-value')
            ->assertJsonPath('success.data.credentials.bucket_endpoint_style', 'path')
            ->assertJsonPath('success.data.credentials.backend_pool', ['http://storage-1.s3.orbit:8333'])
            ->assertJsonPath('success.meta.tool', 'seaweedfs');
    });

    it('returns an empty backend pool when no service route exists', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();
        s3CredCmdSeaweedfsTool($storage);

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.backend_pool', []);
    });

    it('returns empty public endpoints when none are published', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();
        s3CredCmdSeaweedfsTool($storage);

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.public_endpoints', []);
    });

    it('falls back region to orbit when field is not stored', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();
        s3CredCmdSeaweedfsTool($storage, credentials: [
            'fields' => [
                'access_key_id' => 'MYACCESSKEYID12345678',
                'secret_access_key' => 'my-secret-access-key-value',
            ],
        ]);

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.region', 'orbit');
    });

    it('falls back bucket_endpoint_style to path when field is not stored', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();
        s3CredCmdSeaweedfsTool($storage, credentials: [
            'fields' => [
                'access_key_id' => 'MYACCESSKEYID12345678',
                'secret_access_key' => 'my-secret-access-key-value',
            ],
        ]);

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.bucket_endpoint_style', 'path');
    });
});

// ---------------------------------------------------------------------------
// No mutation
// ---------------------------------------------------------------------------

describe('S3Credentials no mutation', function (): void {
    it('does not mutate the database on a successful read', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();
        $tool = s3CredCmdSeaweedfsTool($storage);

        $beforeCredentials = $tool->credentials;
        $beforeConfig = $tool->config;

        s3CredCmdGet($this, ['node' => 'storage-1']);

        $tool->refresh();

        expect($tool->credentials)->toBe($beforeCredentials)
            ->and($tool->config)->toBe($beforeConfig);
    });
});

// ---------------------------------------------------------------------------
// Missing credentials error
// ---------------------------------------------------------------------------

describe('S3Credentials missing credentials', function (): void {
    it('returns s3.credentials_missing when the seaweedfs tool row has no credentials', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();

        NodeTool::factory()->create([
            'node_id' => $storage->id,
            'name' => 'seaweedfs',
            'expected_state' => 'installed',
            'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
            'credentials' => null,
        ]);

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 's3.credentials_missing')
            ->assertJsonPath('error.meta.node', 'storage-1')
            ->assertJsonPath('error.meta.tool', 'seaweedfs');
    });

    it('returns s3.credentials_missing when the seaweedfs tool row has no tool row at all', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 's3.credentials_missing');
    });

    it('returns s3.credentials_missing when access_key_id is empty', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();
        s3CredCmdSeaweedfsTool($storage, credentials: [
            'fields' => [
                'access_key_id' => '',
                'secret_access_key' => 'my-secret-access-key-value',
            ],
        ]);

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 's3.credentials_missing');
    });

    it('returns s3.credentials_missing when secret_access_key is empty', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();
        s3CredCmdSeaweedfsTool($storage, credentials: [
            'fields' => [
                'access_key_id' => 'MYACCESSKEYID12345678',
                'secret_access_key' => '',
            ],
        ]);

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 's3.credentials_missing');
    });

    it('includes next_command in s3.credentials_missing error meta', function (): void {
        s3CredCmdCallerNode(role: 'gateway');
        $storage = s3CredCmdStorageNode();
        s3CredCmdRouterNode();

        NodeTool::factory()->create([
            'node_id' => $storage->id,
            'name' => 'seaweedfs',
            'expected_state' => 'installed',
            'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
            'credentials' => null,
        ]);

        $response = s3CredCmdGet($this, ['node' => 'storage-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.meta.next_command', 'doctor --family=tool --restore --node=storage-1');
    });
});
