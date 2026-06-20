<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\call;

uses(RefreshDatabase::class);

class NodeRemoveDnsEnactorFake extends DevelopmentDnsMappingEnactor
{
    public int $mappingCalls = 0;

    public int $removeCalls = 0;

    /**
     * @param  array<string, mixed>  $result
     */
    public function __construct(private readonly array $result) {}

    /**
     * @return array{
     *     node: string,
     *     tld: string,
     *     domain: string,
     *     target: string,
     * }|null
     */
    public function mappingFor(Node $node): ?array
    {
        $this->mappingCalls++;

        if (! $node->hasActiveRole('app-dev')) {
            return null;
        }

        return [
            'node' => (string) $node->name,
            'tld' => (string) $node->tld,
            'domain' => '*.'.$node->tld,
            'target' => (string) $node->wireguard_address,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function remove(Node $node): array
    {
        $this->removeCalls++;

        return $this->result;
    }
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeRemoveDnsRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'tld' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function assignNodeRemoveDnsRole(string $nodeName, string $role): void
{
    $nodeId = (int) DB::table('nodes')
        ->where('name', $nodeName)
        ->value('id');

    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => 'active',
    ]);
}

/**
 * @return array{code: string, message: string, family: string, next_command: string}
 */
function nodeRemoveDnsWarning(string $message = 'Development DNS mapping could not be removed.'): array
{
    return [
        'code' => 'node.role_baseline_mismatch',
        'message' => $message,
        'family' => 'node',
        'next_command' => 'doctor --family=node --restore',
    ];
}

function fakeNodeRemoveDnsResult(array $result): NodeRemoveDnsEnactorFake
{
    $fake = new NodeRemoveDnsEnactorFake($result);

    app()->instance(DevelopmentDnsMappingEnactor::class, $fake);

    return $fake;
}

function setupNodeRemoveDnsGatewayApiCaller(): void
{

    $callerId = (int) DB::table('nodes')->insertGetId(nodeRemoveDnsRow([
        'name' => 'control-api',
        'host' => '10.6.0.99',
        'wireguard_address' => '10.6.0.99',
        'tld' => null,
    ]));

    $gatewayId = (int) DB::table('nodes')->insertGetId(nodeRemoveDnsRow([
        'name' => 'gateway-1',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'tld' => null,
    ]));

    NodeRoleAssignment::factory()->create([
        'node_id' => $gatewayId,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    DB::table('node_access')->insert([
        'consumer_node_id' => $callerId,
        'serving_node_id' => $gatewayId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function deleteNodeRemoveDnsJson(string $uri, array $data): TestResponse
{
    return call(
        'DELETE',
        $uri,
        $data,
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '10.6.0.99',
        ],
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

describe('NodeRemoveController development DNS warnings', function (): void {
    it('returns development DNS warnings from the gateway API removal path', function (): void {
        setupNodeRemoveDnsGatewayApiCaller();
        DB::table('nodes')->insert(nodeRemoveDnsRow());
        assignNodeRemoveDnsRole('app-1', 'app-dev');

        fakeNodeRemoveDnsResult([
            'status' => 'failed',
            'changed' => false,
            'domain' => '*.test',
            'target' => '10.6.0.7',
            'path' => '/tmp/test.conf',
            'reason' => 'file delete error',
        ]);

        $response = deleteNodeRemoveDnsJson('/api/nodes/app-1', [
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ]);

        $response->assertOk()
            ->assertJsonPath('success.meta.warnings', [
                nodeRemoveDnsWarning('Development DNS mapping could not be removed: file delete error'),
            ]);

        expect(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse();
    });

    it('omits development DNS warnings from the gateway API removal path when cleanup succeeds', function (): void {
        setupNodeRemoveDnsGatewayApiCaller();
        DB::table('nodes')->insert(nodeRemoveDnsRow());
        assignNodeRemoveDnsRole('app-1', 'app-dev');

        fakeNodeRemoveDnsResult([
            'status' => 'removed',
            'changed' => true,
            'domain' => '*.test',
            'target' => '10.6.0.7',
            'path' => '/tmp/test.conf',
        ]);

        $response = deleteNodeRemoveDnsJson('/api/nodes/app-1', [
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ]);

        $response->assertOk()
            ->assertJsonMissingPath('success.meta.warnings');
    });
});
