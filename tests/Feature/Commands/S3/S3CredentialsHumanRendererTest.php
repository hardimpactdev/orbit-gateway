<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const S3_CRED_HUMAN_CALLER_WG_IP = '10.6.1.99';

function s3CredHumanCallerNode(string $role = 'gateway'): Node
{
    $node = Node::factory()->create([
        'name' => 'human-cred-caller',
        'host' => S3_CRED_HUMAN_CALLER_WG_IP,
        'wireguard_address' => S3_CRED_HUMAN_CALLER_WG_IP,
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
    ]);

    return $node;
}

function s3CredHumanStorageNode(): Node
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

function s3CredHumanRouterNode(): Node
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
function s3CredHumanSeaweedfsTool(Node $storage, array $credentials = [], array $config = []): NodeTool
{
    return NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'expected_state' => 'installed',
        'config' => array_merge([
            'backend_host' => "{$storage->name}.s3.orbit",
            'public_hosts' => ['s3.example.com'],
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

/**
 * GET s3/credentials in human (non-JSON) mode and return the raw SSE content.
 *
 * @param  array<string, mixed>  $query
 */
function s3CredHumanGet(object $test, array $query = []): string
{
    $queryString = $query !== [] ? '?'.http_build_query($query) : '';

    $response = $test->get('/api/s3/credentials'.$queryString, [
        'REMOTE_ADDR' => S3_CRED_HUMAN_CALLER_WG_IP,
    ]);

    return $response->getContent() ?: '';
}

// ---------------------------------------------------------------------------
// No progress tree
// ---------------------------------------------------------------------------

describe('S3CredentialsHumanRenderer no progress tree', function (): void {
    it('does not emit a progress tree event', function (): void {
        s3CredHumanCallerNode();
        $storage = s3CredHumanStorageNode();
        s3CredHumanRouterNode();
        s3CredHumanSeaweedfsTool($storage);

        $content = s3CredHumanGet($this, ['node' => 'storage-1']);

        // The response is a plain JSON response — never SSE/event-stream.
        expect($content)->not->toContain('event: tree')
            ->and($content)->not->toContain('event: step')
            ->and($content)->not->toContain('event: complete');
    });
});

// ---------------------------------------------------------------------------
// Credential table fields
// ---------------------------------------------------------------------------

describe('S3CredentialsHumanRenderer credential fields', function (): void {
    it('returns a success JSON response with all credential fields', function (): void {
        s3CredHumanCallerNode();
        $storage = s3CredHumanStorageNode();
        s3CredHumanRouterNode();
        s3CredHumanSeaweedfsTool($storage);

        $response = $this->get('/api/s3/credentials?node=storage-1', [
            'REMOTE_ADDR' => S3_CRED_HUMAN_CALLER_WG_IP,
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.node', 'storage-1')
            ->assertJsonPath('success.data.credentials.private_endpoint', 'https://s3.orbit')
            ->assertJsonPath('success.data.credentials.region', 'orbit')
            ->assertJsonPath('success.data.credentials.access_key_id', 'TESTACCESSKEYID12345')
            ->assertJsonPath('success.data.credentials.secret_access_key', 'test-secret-access-key-value')
            ->assertJsonPath('success.data.credentials.bucket_endpoint_style', 'path')
            ->assertJsonPath('success.meta.tool', 'seaweedfs');
    });
});

// ---------------------------------------------------------------------------
// Missing credential prose
// ---------------------------------------------------------------------------

describe('S3CredentialsHumanRenderer missing credentials', function (): void {
    it('returns s3.credentials_missing error for the CLI to format', function (): void {
        s3CredHumanCallerNode();
        $storage = s3CredHumanStorageNode();
        s3CredHumanRouterNode();

        NodeTool::factory()->create([
            'node_id' => $storage->id,
            'name' => 'seaweedfs',
            'expected_state' => 'installed',
            'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
            'credentials' => null,
        ]);

        $response = $this->get('/api/s3/credentials?node=storage-1', [
            'REMOTE_ADDR' => S3_CRED_HUMAN_CALLER_WG_IP,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 's3.credentials_missing')
            ->assertJsonPath('error.meta.next_command', 'doctor --family=tool --restore --node=storage-1');
    });
});

// ---------------------------------------------------------------------------
// Prerequisite failure prose
// ---------------------------------------------------------------------------

describe('S3CredentialsHumanRenderer prerequisite failures', function (): void {
    it('returns validation_failed for missing active s3 node', function (): void {
        s3CredHumanCallerNode();

        $response = $this->get('/api/s3/credentials?node=missing-node', [
            'REMOTE_ADDR' => S3_CRED_HUMAN_CALLER_WG_IP,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node');
    });

    it('returns validation_failed for missing active router', function (): void {
        s3CredHumanCallerNode();
        s3CredHumanStorageNode();

        $response = $this->get('/api/s3/credentials?node=storage-1', [
            'REMOTE_ADDR' => S3_CRED_HUMAN_CALLER_WG_IP,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'router');
    });
});
