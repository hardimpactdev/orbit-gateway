<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const S3_UNPUBLISH_HUMAN_CALLER_WG_IP = '10.6.0.96';

function s3UnpublishHumanCallerNode(string $role = 'gateway'): Node
{
    $node = Node::factory()->create([
        'name' => 'human-caller',
        'host' => S3_UNPUBLISH_HUMAN_CALLER_WG_IP,
        'wireguard_address' => S3_UNPUBLISH_HUMAN_CALLER_WG_IP,
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
    ]);

    return $node;
}

function s3UnpublishHumanStorageNode(): Node
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

function s3UnpublishHumanRouterNode(): Node
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
 * @param  array<string, mixed>  $config
 */
function s3UnpublishHumanSeaweedfsTool(Node $storage, array $config = []): NodeTool
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
 * DELETE to the S3 unpublish stream endpoint (human mode).
 *
 * @param  array<string, mixed>  $payload
 */
function s3UnpublishHumanStream(object $test, string $host = 's3.example.com', array $payload = []): string
{
    $response = $test->call('DELETE', "/api/s3/public-hosts/{$host}", $payload, [], [], [
        'HTTP_ACCEPT' => 'text/event-stream',
        'REMOTE_ADDR' => S3_UNPUBLISH_HUMAN_CALLER_WG_IP,
    ]);

    $response->assertOk();

    return $response->streamedContent();
}

// ---------------------------------------------------------------------------
// Progress tree
// ---------------------------------------------------------------------------

describe('S3UnpublishHumanRenderer progress tree', function (): void {
    it('emits the 6-step progress tree with the documented step labels', function (): void {
        s3UnpublishHumanCallerNode();
        $storage = s3UnpublishHumanStorageNode();
        s3UnpublishHumanSeaweedfsTool($storage);
        s3UnpublishHumanRouterNode();

        $content = s3UnpublishHumanStream($this, 's3.example.com', ['node' => 'storage-1']);

        expect($content)->toContain('event: tree')
            ->and($content)->toContain('Unpublishing S3 Host')
            ->and($content)->toContain('Confirm destructive removal')
            ->and($content)->toContain('Resolve S3 node')
            ->and($content)->toContain('Check router')
            ->and($content)->toContain('Remove ingress host')
            ->and($content)->toContain('Remove SeaweedFS public host config')
            ->and($content)->toContain('Apply route cleanup');
    });

    it('emits step events for each progress phase', function (): void {
        s3UnpublishHumanCallerNode();
        $storage = s3UnpublishHumanStorageNode();
        s3UnpublishHumanSeaweedfsTool($storage);
        s3UnpublishHumanRouterNode();

        $content = s3UnpublishHumanStream($this, 's3.example.com', ['node' => 'storage-1']);

        expect($content)->toContain('event: step')
            ->and($content)->toContain('confirm_destructive')
            ->and($content)->toContain('resolve_node')
            ->and($content)->toContain('check_router')
            ->and($content)->toContain('remove_ingress')
            ->and($content)->toContain('remove_seaweedfs_config')
            ->and($content)->toContain('apply_cleanup');
    });
});

// ---------------------------------------------------------------------------
// Success summary
// ---------------------------------------------------------------------------

describe('S3UnpublishHumanRenderer success summary', function (): void {
    it('emits a complete frame containing node, private endpoint, host, action, and already_absent', function (): void {
        s3UnpublishHumanCallerNode();
        $storage = s3UnpublishHumanStorageNode();
        s3UnpublishHumanSeaweedfsTool($storage);
        s3UnpublishHumanRouterNode();

        $content = s3UnpublishHumanStream($this, 's3.example.com', ['node' => 'storage-1']);

        expect($content)->toContain('event: complete')
            ->and($content)->toContain('"node":"storage-1"')
            ->and($content)->toContain('"host":"s3.example.com"')
            ->and($content)->toContain('"action":"unpublished"')
            ->and($content)->toContain('"already_absent":false');

        $frame = s3UnpublishHumanParseCompleteFrame($content);
        expect($frame['data']['s3']['private_endpoint'])->toBe('https://s3.orbit')
            ->and($frame['data']['s3']['public_endpoints'])->toBeArray()
            ->and($frame['data']['s3']['public_endpoints'])->not->toContain('https://s3.example.com');
    });
});

// ---------------------------------------------------------------------------
// Absent output
// ---------------------------------------------------------------------------

describe('S3UnpublishHumanRenderer absent output', function (): void {
    it('sets already_absent=true and action=unpublished when host was already absent', function (): void {
        s3UnpublishHumanCallerNode();
        $storage = s3UnpublishHumanStorageNode();
        s3UnpublishHumanSeaweedfsTool($storage, ['public_hosts' => []]);
        s3UnpublishHumanRouterNode();

        $content = s3UnpublishHumanStream($this, 's3.example.com', ['node' => 'storage-1']);

        expect($content)->toContain('event: complete')
            ->and($content)->toContain('"already_absent":true')
            ->and($content)->toContain('"action":"unpublished"');
    });
});

// ---------------------------------------------------------------------------
// Destructive consent messaging
// ---------------------------------------------------------------------------

describe('S3UnpublishHumanRenderer destructive consent messaging', function (): void {
    it('names the destructive consent error in the streaming frame when node prerequisite fails', function (): void {
        s3UnpublishHumanCallerNode();
        // No s3 role node registered.

        $response = $this->call('DELETE', '/api/s3/public-hosts/s3.example.com', [
            'node' => 'storage-1',
        ], [], [], [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => S3_UNPUBLISH_HUMAN_CALLER_WG_IP,
        ]);

        $content = $response->streamedContent();

        expect($content)->toContain('event: error')
            ->and($content)->toContain('validation_failed')
            ->and($content)->toContain('"required_role":"s3"');
    });
});

// ---------------------------------------------------------------------------
// Prerequisite failure prose
// ---------------------------------------------------------------------------

describe('S3UnpublishHumanRenderer prerequisite failure', function (): void {
    it('names the missing s3 node prerequisite in the error frame', function (): void {
        s3UnpublishHumanCallerNode();

        $response = $this->call('DELETE', '/api/s3/public-hosts/s3.example.com', [
            'node' => 'storage-1',
        ], [], [], [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => S3_UNPUBLISH_HUMAN_CALLER_WG_IP,
        ]);

        $content = $response->streamedContent();

        expect($content)->toContain('event: error')
            ->and($content)->toContain('validation_failed')
            ->and($content)->toContain('"required_role":"s3"');
    });

    it('names the missing router prerequisite in the error frame', function (): void {
        s3UnpublishHumanCallerNode();
        $storage = s3UnpublishHumanStorageNode();
        s3UnpublishHumanSeaweedfsTool($storage);
        // No router.

        $response = $this->call('DELETE', '/api/s3/public-hosts/s3.example.com', [
            'node' => 'storage-1',
        ], [], [], [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => S3_UNPUBLISH_HUMAN_CALLER_WG_IP,
        ]);

        $content = $response->streamedContent();

        expect($content)->toContain('event: error')
            ->and($content)->toContain('validation_failed')
            ->and($content)->toContain('"required_role":"router"');
    });
});

// ---------------------------------------------------------------------------
// Cleanup-failure recovery output
// ---------------------------------------------------------------------------

describe('S3UnpublishHumanRenderer cleanup-failure recovery', function (): void {
    it('emits an error frame for prerequisite failures (apply path)', function (): void {
        s3UnpublishHumanCallerNode();

        $response = $this->call('DELETE', '/api/s3/public-hosts/s3.example.com', [
            'node' => 'nonexistent-storage',
        ], [], [], [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => S3_UNPUBLISH_HUMAN_CALLER_WG_IP,
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
function s3UnpublishHumanParseCompleteFrame(string $content): array
{
    preg_match_all('/event: (complete|error)\ndata: (.+)/m', $content, $matches, PREG_SET_ORDER);

    if ($matches === []) {
        return [];
    }

    $last = end($matches);
    $data = $last[2] ?? '{}';

    return json_decode($data, associative: true, flags: JSON_THROW_ON_ERROR);
}
