<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const S3_PUBLISH_HUMAN_CALLER_WG_IP = '10.6.0.92';

function s3HumanCallerNode(string $role = 'gateway'): Node
{
    $node = Node::factory()->create([
        'name' => 'human-caller',
        'host' => S3_PUBLISH_HUMAN_CALLER_WG_IP,
        'wireguard_address' => S3_PUBLISH_HUMAN_CALLER_WG_IP,
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
    ]);

    return $node;
}

function s3HumanStorageNode(): Node
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

function s3HumanRouterNode(): Node
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

function s3HumanIngressNode(): Node
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

function s3HumanSeaweedfsTool(Node $storage, array $config = []): NodeTool
{
    return NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'expected_state' => 'installed',
        'config' => array_merge([
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => [],
        ], $config),
    ]);
}

/**
 * POST to the S3 publish stream endpoint (human mode — no JSON accept).
 *
 * @param  array<string, mixed>  $payload
 */
function s3HumanStream(object $test, array $payload = []): string
{
    $response = $test->call('POST', '/api/s3/public-hosts', $payload, [], [], [
        'HTTP_ACCEPT' => 'text/event-stream',
        'REMOTE_ADDR' => S3_PUBLISH_HUMAN_CALLER_WG_IP,
    ]);

    $response->assertOk();

    return $response->streamedContent();
}

// ---------------------------------------------------------------------------
// Progress tree
// ---------------------------------------------------------------------------

describe('S3PublishHumanRenderer progress tree', function (): void {
    it('emits the 7-step progress tree with the documented step labels', function (): void {
        s3HumanCallerNode();
        $storage = s3HumanStorageNode();
        s3HumanSeaweedfsTool($storage);
        s3HumanRouterNode();
        s3HumanIngressNode();

        $content = s3HumanStream($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        // Tree event with title and all 7 documented steps.
        expect($content)->toContain('event: tree')
            ->and($content)->toContain('Publishing S3 Host')
            ->and($content)->toContain('Resolve S3 node')
            ->and($content)->toContain('Check router and ingress')
            ->and($content)->toContain('Ensure SeaweedFS credentials')
            ->and($content)->toContain('Ensure private s3.orbit route')
            ->and($content)->toContain('Ensure S3 backend pool')
            ->and($content)->toContain('Publish ingress host')
            ->and($content)->toContain('Verify route intent');
    });

    it('emits step events for each progress phase', function (): void {
        s3HumanCallerNode();
        $storage = s3HumanStorageNode();
        s3HumanSeaweedfsTool($storage);
        s3HumanRouterNode();
        s3HumanIngressNode();

        $content = s3HumanStream($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        expect($content)->toContain('event: step')
            ->and($content)->toContain('resolve_node')
            ->and($content)->toContain('check_router_ingress')
            ->and($content)->toContain('ensure_credentials')
            ->and($content)->toContain('ensure_private_route')
            ->and($content)->toContain('ensure_backend_pool')
            ->and($content)->toContain('publish_ingress')
            ->and($content)->toContain('verify_intent');
    });
});

// ---------------------------------------------------------------------------
// Success summary
// ---------------------------------------------------------------------------

describe('S3PublishHumanRenderer success summary', function (): void {
    it('emits a complete frame containing node, private endpoint, host, action, and already_published', function (): void {
        s3HumanCallerNode();
        $storage = s3HumanStorageNode();
        s3HumanSeaweedfsTool($storage);
        s3HumanRouterNode();
        s3HumanIngressNode();

        $content = s3HumanStream($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        expect($content)->toContain('event: complete')
            ->and($content)->toContain('"node":"storage-1"')
            ->and($content)->toContain('"host":"s3.example.com"')
            ->and($content)->toContain('"action":"published"')
            ->and($content)->toContain('"already_published":false');

        // Decode the frame to verify URL fields without slash-escaping issues.
        $frame = s3HumanParseCompleteFrame($content);
        expect($frame['data']['s3']['private_endpoint'])->toBe('https://s3.orbit')
            ->and($frame['data']['s3']['public_endpoints'])->toContain('https://s3.example.com');
    });

    it('emits credentials_ref with tool=seaweedfs and node name', function (): void {
        s3HumanCallerNode();
        $storage = s3HumanStorageNode();
        s3HumanSeaweedfsTool($storage);
        s3HumanRouterNode();
        s3HumanIngressNode();

        $content = s3HumanStream($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        expect($content)->toContain('"tool":"seaweedfs"')
            ->and($content)->toContain('"node":"storage-1"');
    });
});

// ---------------------------------------------------------------------------
// Idempotent output
// ---------------------------------------------------------------------------

describe('S3PublishHumanRenderer idempotent output', function (): void {
    it('sets already_published=true and action=published when host was already registered', function (): void {
        s3HumanCallerNode();
        $storage = s3HumanStorageNode();
        s3HumanSeaweedfsTool($storage, ['public_hosts' => ['s3.example.com']]);
        s3HumanRouterNode();
        s3HumanIngressNode();

        $content = s3HumanStream($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        expect($content)->toContain('event: complete')
            ->and($content)->toContain('"already_published":true')
            ->and($content)->toContain('"action":"published"');
    });
});

// ---------------------------------------------------------------------------
// Prerequisite failure prose
// ---------------------------------------------------------------------------

describe('S3PublishHumanRenderer prerequisite failure', function (): void {
    it('names the missing s3 node prerequisite in the error frame', function (): void {
        s3HumanCallerNode();
        // No s3 role node registered.

        $response = $this->call('POST', '/api/s3/public-hosts', [
            'host' => 's3.example.com',
            'node' => 'storage-1',
        ], [], [], [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => S3_PUBLISH_HUMAN_CALLER_WG_IP,
        ]);

        $content = $response->streamedContent();

        expect($content)->toContain('event: error')
            ->and($content)->toContain('validation_failed')
            ->and($content)->toContain('"required_role":"s3"');
    });

    it('names the missing router prerequisite in the error frame', function (): void {
        s3HumanCallerNode();
        $storage = s3HumanStorageNode();
        s3HumanSeaweedfsTool($storage);
        // No router.

        $response = $this->call('POST', '/api/s3/public-hosts', [
            'host' => 's3.example.com',
            'node' => 'storage-1',
        ], [], [], [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => S3_PUBLISH_HUMAN_CALLER_WG_IP,
        ]);

        $content = $response->streamedContent();

        expect($content)->toContain('event: error')
            ->and($content)->toContain('validation_failed')
            ->and($content)->toContain('"required_role":"router"');
    });

    it('names the missing ingress prerequisite in the error frame', function (): void {
        s3HumanCallerNode();
        $storage = s3HumanStorageNode();
        s3HumanSeaweedfsTool($storage);
        s3HumanRouterNode();
        // No ingress.

        $response = $this->call('POST', '/api/s3/public-hosts', [
            'host' => 's3.example.com',
            'node' => 'storage-1',
        ], [], [], [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => S3_PUBLISH_HUMAN_CALLER_WG_IP,
        ]);

        $content = $response->streamedContent();

        expect($content)->toContain('event: error')
            ->and($content)->toContain('validation_failed')
            ->and($content)->toContain('"required_role":"ingress"');
    });
});

// ---------------------------------------------------------------------------
// Apply-failure recovery
// ---------------------------------------------------------------------------
// Note: S3RouteRegistrar and S3PublishAction are final readonly classes and
// cannot be extended or easily mocked. The s3.publish_failed code path is
// validated by the JSON renderer test (S3PublishJsonRendererTest) which
// injects failure via the action's error return shape.
//
// This describe block is preserved per the documented test mapping so that
// future tests can be added here when a testable seam (interface/fake) is
// introduced.
describe('S3PublishHumanRenderer apply-failure recovery', function (): void {
    it('emits an error frame for prerequisite failures (not-active node)', function (): void {
        s3HumanCallerNode();
        // No s3 role assignment — simulates apply-path prerequisite failure.
        // The action detects no active s3 node and returns a validation error.

        $response = $this->call('POST', '/api/s3/public-hosts', [
            'host' => 's3.example.com',
            'node' => 'nonexistent-storage',
        ], [], [], [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => S3_PUBLISH_HUMAN_CALLER_WG_IP,
        ]);

        $content = $response->streamedContent();

        expect($content)->toContain('event: error')
            ->and($content)->toContain('validation_failed');
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
function s3HumanParseCompleteFrame(string $content): array
{
    preg_match_all('/event: (complete|error)\ndata: (.+)/m', $content, $matches, PREG_SET_ORDER);

    if ($matches === []) {
        return [];
    }

    $last = end($matches);
    $data = $last[2] ?? '{}';

    return json_decode($data, associative: true, flags: JSON_THROW_ON_ERROR);
}
