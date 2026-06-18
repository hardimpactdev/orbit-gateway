<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\S3\S3CredentialGenerator;
use App\Services\S3\S3ServiceConfig;
use App\Services\S3\S3ServiceConfigResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function s3ResolverNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'storage-1',
        'host' => 'storage-1.example.com',
        'wireguard_address' => '10.6.0.44',
    ], $overrides));
}

function s3RoleAssignment(Node $node, array $settings = []): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 's3',
        'status' => 'active',
        'settings' => $settings,
    ]);
}

function s3SeaweedfsTool(Node $node, array $overrides = []): NodeTool
{
    return NodeTool::factory()->create(array_merge([
        'node_id' => $node->id,
        'name' => 'seaweedfs',
        'expected_state' => 'installed',
        'config' => ['public_hosts' => []],
        'credentials' => null,
    ], $overrides));
}

function s3Resolver(): S3ServiceConfigResolver
{
    return new S3ServiceConfigResolver(new S3CredentialGenerator);
}

// ---------------------------------------------------------------------------
// Credential preservation
// ---------------------------------------------------------------------------

it('preserves existing credentials from the seaweedfs tool row', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node);
    $seaweedfs = s3SeaweedfsTool($node, [
        'credentials' => [
            'fields' => [
                'access_key_id' => 'EXISTINGACCESSKEYID12',
                'secret_access_key' => 'existing-secret-access-key-value-here',
            ],
        ],
    ]);

    $config = s3Resolver()->resolve($node, $assignment, $seaweedfs);

    expect($config->accessKeyId)->toBe('EXISTINGACCESSKEYID12')
        ->and($config->secretAccessKey)->toBe('existing-secret-access-key-value-here');
});

it('generates new credentials when the seaweedfs tool row has no credentials', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node);
    $seaweedfs = s3SeaweedfsTool($node, ['credentials' => null]);

    $config = s3Resolver()->resolve($node, $assignment, $seaweedfs);

    expect($config->accessKeyId)->toBeString()->not->toBeEmpty()
        ->and($config->secretAccessKey)->toBeString()->not->toBeEmpty();
});

it('generates new credentials when the credentials fields array is empty', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node);
    $seaweedfs = s3SeaweedfsTool($node, [
        'credentials' => ['fields' => []],
    ]);

    $config = s3Resolver()->resolve($node, $assignment, $seaweedfs);

    expect($config->accessKeyId)->toBeString()->not->toBeEmpty()
        ->and($config->secretAccessKey)->toBeString()->not->toBeEmpty();
});

it('generates new credentials when the access_key_id is missing', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node);
    $seaweedfs = s3SeaweedfsTool($node, [
        'credentials' => [
            'fields' => [
                'secret_access_key' => 'only-secret-no-key-id',
            ],
        ],
    ]);

    $config = s3Resolver()->resolve($node, $assignment, $seaweedfs);

    // Generated credentials will differ from the partial stored ones
    expect($config->accessKeyId)->toBeString()->not->toBeEmpty();
});

it('generates new credentials when the secret_access_key is missing', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node);
    $seaweedfs = s3SeaweedfsTool($node, [
        'credentials' => [
            'fields' => [
                'access_key_id' => 'ONLYKEYNIDSECRET12345',
            ],
        ],
    ]);

    $config = s3Resolver()->resolve($node, $assignment, $seaweedfs);

    expect($config->secretAccessKey)->toBeString()->not->toBeEmpty();
});

it('generates credentials when no seaweedfs tool row is provided', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node);

    $config = s3Resolver()->resolve($node, $assignment, null);

    expect($config->accessKeyId)->toBeString()->not->toBeEmpty()
        ->and($config->secretAccessKey)->toBeString()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Endpoint and backend bind
// ---------------------------------------------------------------------------

it('resolves the stable s3.orbit service endpoint', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node);

    $config = s3Resolver()->resolve($node, $assignment, null);

    expect($config->serviceEndpoint())->toBe('https://s3.orbit');
});

it('resolves the backend bind as wireguard_address:8333', function (): void {
    $node = s3ResolverNode(['wireguard_address' => '10.6.0.44']);
    $assignment = s3RoleAssignment($node);

    $config = s3Resolver()->resolve($node, $assignment, null);

    expect($config->backendBind())->toBe('10.6.0.44:8333');
});

it('reflects different wireguard addresses in the backend bind', function (): void {
    $node = s3ResolverNode(['wireguard_address' => '10.6.0.99']);
    $assignment = s3RoleAssignment($node);

    $config = s3Resolver()->resolve($node, $assignment, null);

    expect($config->backendBind())->toBe('10.6.0.99:8333');
});

// ---------------------------------------------------------------------------
// Data path
// ---------------------------------------------------------------------------

it('uses the default data path when the assignment carries no settings', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node, []);

    $config = s3Resolver()->resolve($node, $assignment, null);

    expect($config->dataPath)->toBe(S3RoleSettings::DefaultDataPath);
});

it('uses a configured data path from the role assignment settings', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node, ['data_path' => '/mnt/fast-disk/s3']);

    $config = s3Resolver()->resolve($node, $assignment, null);

    expect($config->dataPath)->toBe('/mnt/fast-disk/s3');
});

// ---------------------------------------------------------------------------
// S3ServiceConfig shape
// ---------------------------------------------------------------------------

it('returns an S3ServiceConfig value object', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node);

    $result = s3Resolver()->resolve($node, $assignment, null);

    expect($result)->toBeInstanceOf(S3ServiceConfig::class);
});

it('includes the node name in the resolved config', function (): void {
    $node = s3ResolverNode(['name' => 'storage-99']);
    $assignment = s3RoleAssignment($node);

    $config = s3Resolver()->resolve($node, $assignment, null);

    expect($config->nodeName)->toBe('storage-99');
});

it('includes the wireguard address in the resolved config', function (): void {
    $node = s3ResolverNode(['wireguard_address' => '10.6.0.55']);
    $assignment = s3RoleAssignment($node);

    $config = s3Resolver()->resolve($node, $assignment, null);

    expect($config->wireguardAddress)->toBe('10.6.0.55');
});

// ---------------------------------------------------------------------------
// Public hosts
// ---------------------------------------------------------------------------

it('resolves public hosts from the seaweedfs tool config', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node);
    $seaweedfs = s3SeaweedfsTool($node, [
        'config' => ['public_hosts' => ['s3.example.com', 's3.other.com']],
        'credentials' => [
            'fields' => [
                'access_key_id' => 'SOMEACCESSKEYID12345',
                'secret_access_key' => 'some-secret-access-key-value-here',
            ],
        ],
    ]);

    $config = s3Resolver()->resolve($node, $assignment, $seaweedfs);

    expect($config->publicHosts)->toBe(['s3.example.com', 's3.other.com']);
});

it('defaults to empty public hosts when tool row has no config', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node);
    $seaweedfs = s3SeaweedfsTool($node, ['config' => null]);

    $config = s3Resolver()->resolve($node, $assignment, $seaweedfs);

    expect($config->publicHosts)->toBe([]);
});

it('defaults to empty public hosts when no seaweedfs tool row is provided', function (): void {
    $node = s3ResolverNode();
    $assignment = s3RoleAssignment($node);

    $config = s3Resolver()->resolve($node, $assignment, null);

    expect($config->publicHosts)->toBe([]);
});

// ---------------------------------------------------------------------------
// Error conditions
// ---------------------------------------------------------------------------

it('throws when the node has no wireguard address', function (): void {
    $node = s3ResolverNode(['wireguard_address' => null]);
    $assignment = s3RoleAssignment($node);

    expect(fn () => s3Resolver()->resolve($node, $assignment, null))
        ->toThrow(RuntimeException::class, 'The s3 role requires a WireGuard address');
});

it('throws when the node wireguard address is an empty string', function (): void {
    $node = s3ResolverNode(['wireguard_address' => '']);
    $assignment = s3RoleAssignment($node);

    expect(fn () => s3Resolver()->resolve($node, $assignment, null))
        ->toThrow(RuntimeException::class, 'The s3 role requires a WireGuard address');
});
