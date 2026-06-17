<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\S3RoleSettings;
use App\Models\Node;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\S3\S3RuntimeContainer;
use App\Services\S3\S3RuntimeContainerRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function s3RuntimeRenderer(): S3RuntimeContainerRenderer
{
    return new S3RuntimeContainerRenderer(new OrbitContainerNames);
}

function s3RuntimeNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'storage-1',
        'host' => 'storage-1.example.com',
        'wireguard_address' => '10.6.0.10',
    ], $overrides));
}

it('renders the chrislusf/seaweedfs:4.33 image', function (): void {
    $node = s3RuntimeNode();
    $container = s3RuntimeRenderer()->render($node, new S3RoleSettings);

    expect($container->image())->toBe('chrislusf/seaweedfs:4.33');
});

it('renders the default data path mounted at /data', function (): void {
    $node = s3RuntimeNode();
    $container = s3RuntimeRenderer()->render($node, new S3RoleSettings);

    expect($container->mounts())->toContain([
        'source' => '/srv/orbit/s3/data',
        'target' => '/data',
        'read_only' => false,
    ]);
});

it('renders a configured data path mounted at /data', function (): void {
    $node = s3RuntimeNode();
    $settings = new S3RoleSettings(dataPath: '/mnt/fast-disk/s3');
    $container = s3RuntimeRenderer()->render($node, $settings);

    expect($container->mounts())->toContain([
        'source' => '/mnt/fast-disk/s3',
        'target' => '/data',
        'read_only' => false,
    ]);
});

it('binds the S3 API only to the node WireGuard address on port 8333', function (): void {
    $node = s3RuntimeNode(['wireguard_address' => '10.6.0.10']);
    $container = s3RuntimeRenderer()->render($node, new S3RoleSettings);

    expect($container->publishedPorts())->toBe(['10.6.0.10:8333:8333'])
        ->and($container->environment())->toBe([]);
});

it('renders the SeaweedFS server command with the S3 config path', function (): void {
    $node = s3RuntimeNode();
    $container = s3RuntimeRenderer()->render($node, new S3RoleSettings);

    expect($container->command())
        ->toContain('weed server -filer -s3')
        ->toContain('-s3.port=8333')
        ->toContain('-s3.config=/etc/seaweedfs/s3.json');
});

it('does not bind to 0.0.0.0 or a public interface', function (): void {
    $node = s3RuntimeNode(['wireguard_address' => '10.6.0.10']);
    $container = s3RuntimeRenderer()->render($node, new S3RoleSettings);

    $ports = implode(' ', $container->publishedPorts());
    $envValues = implode(' ', $container->environment());

    expect($ports)->not->toContain('0.0.0.0')
        ->and($envValues)->not->toContain('0.0.0.0')
        ->and($ports)->not->toContain(':8333:8333', 2);
});

it('renders a deterministic S3 runtime container', function (): void {
    $node = s3RuntimeNode();
    $container = s3RuntimeRenderer()->render($node, new S3RoleSettings);

    expect($container)->toBeInstanceOf(S3RuntimeContainer::class)
        ->and($container->name())->toBe('orbit-seaweedfs')
        ->and($container->image())->toBe('chrislusf/seaweedfs:4.33')
        ->and($container->network())->toBe('orbit-network')
        ->and($container->restartPolicy())->toBe('unless-stopped')
        ->and($container->wireGuardAddress())->toBe('10.6.0.10')
        ->and($container->mounts())->toHaveCount(2)
        ->and($container->mounts())->toContain([
            'source' => '/srv/orbit/s3/data',
            'target' => '/data',
            'read_only' => false,
        ])
        ->and($container->mounts())->toContain([
            'source' => '/srv/orbit/s3/data/s3.json',
            'target' => '/etc/seaweedfs/s3.json',
            'read_only' => true,
        ]);
});

it('exposes labels with the spec hash and s3-runtime kind', function (): void {
    $container = s3RuntimeRenderer()->render(s3RuntimeNode(), new S3RoleSettings);

    expect($container->labels())->toMatchArray([
        'orbit.managed' => 'true',
        'orbit.container.kind' => 's3-runtime',
    ])
        ->and($container->labels()[S3RuntimeContainer::SpecHashLabel] ?? null)->toBe($container->specHash());
});

it('changes the spec hash when the data path changes', function (): void {
    $node = s3RuntimeNode();

    $containerA = s3RuntimeRenderer()->render($node, new S3RoleSettings(dataPath: '/srv/orbit/s3/data'));
    $containerB = s3RuntimeRenderer()->render($node, new S3RoleSettings(dataPath: '/mnt/alt/s3'));

    expect($containerA->specHash())->not->toBe($containerB->specHash());
});

it('changes the spec hash when the WireGuard address changes', function (): void {
    $nodeA = s3RuntimeNode(['wireguard_address' => '10.6.0.10']);
    $nodeB = s3RuntimeNode(['name' => 'storage-2', 'wireguard_address' => '10.6.0.11']);

    $containerA = s3RuntimeRenderer()->render($nodeA, new S3RoleSettings);
    $containerB = s3RuntimeRenderer()->render($nodeB, new S3RoleSettings);

    expect($containerA->specHash())->not->toBe($containerB->specHash());
});

it('throws when the node has no WireGuard address', function (): void {
    $node = s3RuntimeNode(['wireguard_address' => null]);

    expect(fn () => s3RuntimeRenderer()->render($node, new S3RoleSettings))
        ->toThrow(RuntimeException::class, 'The s3 role requires a WireGuard address before runtime config can be rendered.');
});
