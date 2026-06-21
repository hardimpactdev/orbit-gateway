<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\S3\S3ServiceConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regression tests ensuring `tool:credentials seaweedfs` and `s3:credentials` agree on the
 * region value — both must surface 'orbit', and the stored credential field written by
 * S3ServiceConfigurator must also be 'orbit'.
 *
 * Both surfaces read the same service-level credentials from the `seaweedfs` NodeTool row,
 * so the field values agree by construction. The `s3` role is a tool-host role (the
 * `seaweedfs` tool's credentials, logs, and lifecycle are visible through the tool command
 * family on the s3 node), so both commands resolve the same pure-s3 node. S3ServiceConfig::Region
 * is the canonical constant S3ServiceConfigurator::writeCredentials must use.
 */
const S3_REGION_AGREE_CALLER_WG_IP = '10.6.1.101';

function regionAgreementCallerNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'region-caller',
        'host' => S3_REGION_AGREE_CALLER_WG_IP,
        'wireguard_address' => S3_REGION_AGREE_CALLER_WG_IP,
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $node;
}

function regionAgreementStorageNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'storage-1',
        'host' => '10.6.0.44',
        'wireguard_address' => '10.6.0.44',
        'status' => 'active',
    ]);

    // Pure s3 role — the only valid topology for an S3 serving node (s3 conflicts
    // with the agent/app tool-host roles). The s3 role is itself a tool host, so the
    // seaweedfs tool is reachable through the tool command family on this node.
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 's3',
        'status' => 'active',
    ]);

    return $node;
}

function regionAgreementRouterNode(): Node
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
 * Seed a seaweedfs tool row with an 'orbit' region in fields — as S3ServiceConfigurator
 * now writes it.
 */
function regionAgreementSeaweedfsTool(Node $storage): NodeTool
{
    return NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'seaweedfs',
        'expected_state' => 'installed',
        'config' => ['backend_host' => 'storage-1.s3.orbit', 'public_hosts' => []],
        'credentials' => [
            'fields' => [
                'access_key_id' => 'REGIONTESTACCESSKEYID',
                'secret_access_key' => 'region-test-secret-access-key',
                'region' => 'orbit',
                'endpoint' => 'https://s3.orbit',
                'bucket_style' => 'path',
            ],
        ],
    ]);
}

describe('S3 region agreement between tool:credentials and s3:credentials', function (): void {
    it('tool:credentials seaweedfs surfaces region=orbit on a pure-s3 node', function (): void {
        regionAgreementCallerNode(); // gateway role — bypasses tool:credentials auth
        $storage = regionAgreementStorageNode();
        regionAgreementSeaweedfsTool($storage);

        $response = $this->call(
            'GET',
            '/api/tools/seaweedfs/credentials?node=storage-1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => S3_REGION_AGREE_CALLER_WG_IP],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.fields.region', 'orbit');
    });

    it('s3:credentials surfaces region=orbit from stored fields', function (): void {
        regionAgreementCallerNode();
        $storage = regionAgreementStorageNode();
        regionAgreementRouterNode();
        regionAgreementSeaweedfsTool($storage);

        $response = $this->call(
            'GET',
            '/api/s3/credentials?node=storage-1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => S3_REGION_AGREE_CALLER_WG_IP],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.credentials.region', 'orbit');
    });

    it('tool:credentials and s3:credentials both return the same region value', function (): void {
        regionAgreementCallerNode();
        $storage = regionAgreementStorageNode();
        regionAgreementRouterNode();
        regionAgreementSeaweedfsTool($storage);

        $toolResponse = $this->call(
            'GET',
            '/api/tools/seaweedfs/credentials?node=storage-1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => S3_REGION_AGREE_CALLER_WG_IP],
        );

        $s3Response = $this->call(
            'GET',
            '/api/s3/credentials?node=storage-1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => S3_REGION_AGREE_CALLER_WG_IP],
        );

        $toolRegion = $toolResponse->json('success.data.credentials.fields.region');
        $s3Region = $s3Response->json('success.data.credentials.region');

        expect($toolRegion)->toBe('orbit')
            ->and($s3Region)->toBe('orbit')
            ->and($toolRegion)->toBe($s3Region);
    });

    it('S3ServiceConfigurator writes region=orbit to the stored credentials fields', function (): void {
        $node = Node::factory()->create([
            'name' => 'region-storage',
            'wireguard_address' => '10.6.0.88',
            'status' => 'active',
        ]);

        $assignment = NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 's3',
            'status' => 'active',
            'settings' => ['data_path' => '/srv/orbit/s3/data'],
        ]);

        app(S3ServiceConfigurator::class)->configure($node, $assignment);

        $seaweedfsTool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'seaweedfs')
            ->firstOrFail();

        expect($seaweedfsTool->credentials['fields']['region'])->toBe('orbit');
    });
});
