<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const S3_UNPUBLISH_CALLER_WG_IP = '10.6.0.95';

/**
 * @param  array<string, mixed>  $overrides
 */
function s3UnpublishCallerNode(array $overrides = [], string $role = 'gateway'): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => S3_UNPUBLISH_CALLER_WG_IP,
        'wireguard_address' => S3_UNPUBLISH_CALLER_WG_IP,
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
function s3UnpublishStorageNode(array $overrides = []): Node
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

function s3UnpublishRouterNode(): Node
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

function s3UnpublishIngressNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'edge-1',
        'host' => '10.6.0.10',
        'wireguard_address' => '10.6.0.10',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'ingress',
        'status' => 'active',
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $config
 */
function s3UnpublishSeaweedfsTool(Node $storage, array $config = []): NodeTool
{
    return NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'expected_state' => 'installed',
        'config' => array_merge([
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => ['s3.example.com'],
        ], $config),
    ]);
}

/**
 * DELETE to the streaming S3 unpublish endpoint.
 *
 * @param  array<string, mixed>  $payload
 */
function s3UnpublishStream(object $test, string $host = 's3.example.com', array $payload = []): TestResponse
{
    return $test->call('DELETE', "/api/s3/public-hosts/{$host}", $payload, [], [], [
        'HTTP_ACCEPT' => 'text/event-stream',
        'REMOTE_ADDR' => S3_UNPUBLISH_CALLER_WG_IP,
    ]);
}

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

describe('S3Unpublish authorization', function (): void {
    it('rejects unauthenticated callers', function (): void {
        $response = $this->call('DELETE', '/api/s3/public-hosts/s3.example.com', [], [], [], [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => '192.168.99.99',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'authorization_failed');
    });
});

// ---------------------------------------------------------------------------
// Input validation
// ---------------------------------------------------------------------------

describe('S3Unpublish input validation', function (): void {
    it('fails when node is missing and no s3 nodes exist', function (): void {
        s3UnpublishCallerNode();

        $response = s3UnpublishStream($this, 's3.example.com');

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node')
            ->assertJsonPath('error.meta.required_role', 's3');
    });

    it('auto-resolves node when exactly one active s3 node exists', function (): void {
        s3UnpublishCallerNode(role: 'gateway');
        $storage = s3UnpublishStorageNode();
        s3UnpublishSeaweedfsTool($storage);
        s3UnpublishRouterNode();

        $response = s3UnpublishStream($this);

        $response->assertOk();
        $content = $response->streamedContent();
        expect($content)->toContain('event: complete');
    });

    it('fails when node is missing and multiple s3 nodes exist', function (): void {
        s3UnpublishCallerNode(role: 'gateway');
        s3UnpublishStorageNode(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
        s3UnpublishStorageNode(['name' => 'storage-2', 'wireguard_address' => '10.6.0.45']);

        $response = s3UnpublishStream($this);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node');
    });
});

// ---------------------------------------------------------------------------
// Prerequisites
// ---------------------------------------------------------------------------

describe('S3Unpublish prerequisites', function (): void {
    it('fails when the selected node is not an active s3 node', function (): void {
        s3UnpublishCallerNode(role: 'gateway');

        $response = s3UnpublishStream($this, 's3.example.com', ['node' => 'storage-1']);

        $content = $response->streamedContent();
        expect($content)->toContain('event: error')
            ->and($content)->toContain('validation_failed')
            ->and($content)->toContain('"required_role":"s3"');
    });

    it('fails when no active router exists', function (): void {
        s3UnpublishCallerNode(role: 'gateway');
        $storage = s3UnpublishStorageNode();
        s3UnpublishSeaweedfsTool($storage);
        // No router.

        $response = s3UnpublishStream($this, 's3.example.com', ['node' => 'storage-1']);

        $content = $response->streamedContent();
        expect($content)->toContain('event: error')
            ->and($content)->toContain('validation_failed')
            ->and($content)->toContain('"required_role":"router"');
    });

    it('fails when the s3 node has no seaweedfs tool row', function (): void {
        s3UnpublishCallerNode(role: 'gateway');
        s3UnpublishStorageNode();
        s3UnpublishRouterNode();
        // No seaweedfs tool row.

        $response = s3UnpublishStream($this, 's3.example.com', ['node' => 'storage-1']);

        $content = $response->streamedContent();
        expect($content)->toContain('event: error')
            ->and($content)->toContain('validation_failed');
    });
});

// ---------------------------------------------------------------------------
// Owned-route denial
// ---------------------------------------------------------------------------

describe('S3Unpublish owned-route denial', function (): void {
    it('rejects a host owned by a non-S3 proxy route', function (): void {
        s3UnpublishCallerNode(role: 'gateway');
        $storage = s3UnpublishStorageNode();
        s3UnpublishSeaweedfsTool($storage);
        s3UnpublishRouterNode();
        $ingress = s3UnpublishIngressNode();

        ProxyRoute::factory()->create([
            'domain' => 's3.example.com',
            'node_id' => $ingress->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://app.test']],
        ]);

        $response = s3UnpublishStream($this, 's3.example.com', ['node' => 'storage-1']);

        $content = $response->streamedContent();
        expect($content)->toContain('event: error')
            ->and($content)->toContain('proxy.owned_route_denied')
            ->and($content)->toContain('"owner_type":"app"');
    });
});

// ---------------------------------------------------------------------------
// Absent idempotency
// ---------------------------------------------------------------------------

describe('S3Unpublish absent idempotency', function (): void {
    it('returns success with already_absent=true when the host is not published', function (): void {
        s3UnpublishCallerNode(role: 'gateway');
        $storage = s3UnpublishStorageNode();
        s3UnpublishSeaweedfsTool($storage, ['public_hosts' => []]);
        s3UnpublishRouterNode();

        $response = s3UnpublishStream($this, 's3.example.com', ['node' => 'storage-1']);

        $content = $response->streamedContent();
        expect($content)->toContain('event: complete')
            ->and($content)->toContain('"already_absent":true')
            ->and($content)->toContain('"action":"unpublished"');
    });
});

// ---------------------------------------------------------------------------
// Success
// ---------------------------------------------------------------------------

describe('S3Unpublish success', function (): void {
    it('unpublishes the host and returns the expected success shape', function (): void {
        s3UnpublishCallerNode(role: 'gateway');
        $storage = s3UnpublishStorageNode();
        s3UnpublishSeaweedfsTool($storage);
        s3UnpublishRouterNode();

        $response = s3UnpublishStream($this, 's3.example.com', ['node' => 'storage-1']);

        $response->assertOk();
        $content = $response->streamedContent();

        expect($content)->toContain('event: complete')
            ->and($content)->toContain('"node":"storage-1"')
            ->and($content)->toContain('"host":"s3.example.com"')
            ->and($content)->toContain('"action":"unpublished"')
            ->and($content)->toContain('"already_absent":false');

        $frame = s3UnpublishParseCompleteFrame($content);
        expect($frame['data']['s3']['node'])->toBe('storage-1')
            ->and($frame['data']['s3']['private_endpoint'])->toBe('https://s3.orbit')
            ->and($frame['data']['s3']['public_endpoints'])->toBeArray()
            ->and($frame['data']['s3']['public_endpoints'])->not->toContain('https://s3.example.com');
    });

    it('removes the public host from the seaweedfs tool row', function (): void {
        s3UnpublishCallerNode(role: 'gateway');
        $storage = s3UnpublishStorageNode();
        $tool = s3UnpublishSeaweedfsTool($storage);
        s3UnpublishRouterNode();

        $response = s3UnpublishStream($this, 's3.example.com', ['node' => 'storage-1']);
        $response->streamedContent();

        $tool->refresh();
        expect($tool->config['public_hosts'])->not->toContain('s3.example.com');
    });

    it('removes the ingress proxy route for the unpublished host', function (): void {
        s3UnpublishCallerNode(role: 'gateway');
        $storage = s3UnpublishStorageNode();
        s3UnpublishSeaweedfsTool($storage);
        $router = s3UnpublishRouterNode();
        $ingress = s3UnpublishIngressNode();

        ProxyRoute::factory()->create([
            'domain' => 's3.example.com',
            'node_id' => $ingress->id,
            'owner_type' => 's3',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'seaweedfs',
                'protocol' => 's3',
                'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit'],
            ],
        ]);

        $response = s3UnpublishStream($this, 's3.example.com', ['node' => 'storage-1']);
        $response->streamedContent();

        expect(ProxyRoute::query()->where('domain', 's3.example.com')->exists())->toBeFalse();
    });

    it('does not remove other published hosts from the seaweedfs tool row', function (): void {
        s3UnpublishCallerNode(role: 'gateway');
        $storage = s3UnpublishStorageNode();
        $tool = s3UnpublishSeaweedfsTool($storage, ['public_hosts' => ['s3.example.com', 's3.other.com']]);
        s3UnpublishRouterNode();

        $response = s3UnpublishStream($this, 's3.example.com', ['node' => 'storage-1']);
        $response->streamedContent();

        $tool->refresh();
        expect($tool->config['public_hosts'])->toContain('s3.other.com')
            ->and($tool->config['public_hosts'])->not->toContain('s3.example.com');
    });
});

// ---------------------------------------------------------------------------
// Helper functions
// ---------------------------------------------------------------------------

/**
 * Parse the final complete/error frame from SSE stream content.
 *
 * @return array<string, mixed>
 */
function s3UnpublishParseCompleteFrame(string $content): array
{
    preg_match_all('/event: (complete|error)\ndata: (.+)/m', $content, $matches, PREG_SET_ORDER);

    if ($matches === []) {
        return [];
    }

    $last = end($matches);
    $data = $last[2] ?? '{}';

    return json_decode($data, associative: true, flags: JSON_THROW_ON_ERROR);
}
