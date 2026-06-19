<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\Process;
use App\Services\Nodes\NodeWireGuardServiceAddress;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\WebSockets\WebSocketBackendName;
use App\Services\WebSockets\WebSocketRedisResolver;
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\WebSockets\WebSocketRuntimeContainerRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function websocketRuntimeRenderer(): WebSocketRuntimeContainerRenderer
{
    return new WebSocketRuntimeContainerRenderer(
        new OrbitContainerNames,
        new WebSocketBackendName,
        app(WebSocketRedisResolver::class),
        app(NodeWireGuardServiceAddress::class),
    );
}

function websocketRuntimeNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'app-dev-1',
        'host' => 'app-dev-1.example.com',
        'wireguard_address' => '10.6.0.44',
    ], $overrides));
}

function websocketRuntimeRedisNode(array $overrides = []): Node
{
    $node = Node::factory()->database()->create(array_merge([
        'name' => 'redis-1',
        'wireguard_address' => '10.6.0.3',
        'status' => NodeStatus::Active,
    ], $overrides));

    Process::factory()->forOwner($node)->create([
        'name' => 'redis',
        'runtime_config' => ['definition' => 'redis'],
    ]);

    return $node;
}

function websocketRuntimeSettings(?Node $redisNode = null): WebSocketRoleSettings
{
    return new WebSocketRoleSettings(redisNodeId: ($redisNode ?? websocketRuntimeRedisNode())->id);
}

it('uses the websocket node WireGuard address as the backend identity', function (): void {
    $node = websocketRuntimeNode(['wireguard_address' => '10.6.0.44']);

    expect((new WebSocketBackendName)->forNode($node))->toBe('10.6.0.44');
});

it('renders Reverb env with a private WireGuard bind and Redis service config', function (): void {
    $node = websocketRuntimeNode();

    $env = websocketRuntimeRenderer()->env($node, websocketRuntimeSettings());

    expect($env)
        ->toContain('APP_ENV=production')
        ->toContain('APP_DEBUG=false')
        ->toContain('BROADCAST_CONNECTION=reverb')
        ->toContain('CACHE_STORE=array')
        ->toContain('REVERB_SERVER_HOST=10.6.0.44')
        ->toContain('REVERB_SERVER_PORT=8080')
        ->toContain('REVERB_HOST=websocket.orbit')
        ->toContain('REVERB_PORT=443')
        ->toContain('REVERB_SCHEME=https')
        ->toContain('REVERB_SCALING_ENABLED=true')
        ->toContain('REVERB_TLS_CERT=/etc/orbit/certs/10.6.0.44.crt')
        ->toContain('REVERB_TLS_KEY=/etc/orbit/certs/10.6.0.44.key')
        ->toContain('REDIS_HOST=10.6.0.3')
        ->toContain('REDIS_PORT=6379')
        ->not->toContain('REVERB_SERVER_HOST=0.0.0.0')
        ->not->toContain('app-dev-1.example.com')
        ->not->toContain('.websocket.orbit');
});

it('uses the Redis owner WireGuard service address for same-node Redis access', function (): void {
    $node = websocketRuntimeRedisNode([
        'name' => 'app-dev-1',
        'host' => 'app-dev-1.example.com',
        'wireguard_address' => '10.6.0.44',
    ]);

    $env = websocketRuntimeRenderer()->env($node, websocketRuntimeSettings($node));

    expect($env)
        ->toContain('REDIS_HOST=10.6.0.44')
        ->not->toContain('REDIS_HOST=127.0.0.1')
        ->not->toContain('REDIS_HOST=redis');
});

it('renders a deterministic WebSocket runtime container', function (): void {
    $node = websocketRuntimeNode();

    $redisNode = websocketRuntimeRedisNode();

    $container = websocketRuntimeRenderer()->render($node, websocketRuntimeSettings($redisNode));

    expect($container)->toBeInstanceOf(WebSocketRuntimeContainer::class)
        ->and($container->name())->toBe('orbit-websocket-app-dev-1')
        ->and($container->image())->toBe('orbit-reverb:current')
        ->and($container->network())->toBe('orbit-network')
        ->and($container->restartPolicy())->toBe('unless-stopped')
        ->and($container->backendName())->toBe('10.6.0.44')
        ->and($container->redisNodeId())->toBe($redisNode->id)
        ->and($container->workingDirectory())->toBe('/app')
        ->and($container->command())->toBe('php artisan reverb:start --host=10.6.0.44 --port=8080 --hostname=10.6.0.44')
        ->and($container->networkAliases())->toBe([
            'orbit-websocket-app-dev-1',
        ])
        ->and($container->mounts())->toContain([
            'source' => '/opt/orbit/websocket/current',
            'target' => '/app',
            'read_only' => false,
        ])
        ->and($container->mounts())->toContain([
            'source' => '/etc/orbit',
            'target' => '/etc/orbit',
            'read_only' => true,
        ])
        ->and($container->environment())->toMatchArray([
            'REVERB_SERVER_HOST' => '10.6.0.44',
            'REVERB_HOST' => 'websocket.orbit',
            'REVERB_TLS_CERT' => '/etc/orbit/certs/10.6.0.44.crt',
            'REVERB_TLS_KEY' => '/etc/orbit/certs/10.6.0.44.key',
            'REDIS_HOST' => '10.6.0.3',
        ]);
});

it('renders a self-contained WebSocket runtime container without a source mount', function (): void {
    $container = websocketRuntimeRenderer()->render(
        websocketRuntimeNode(),
        websocketRuntimeSettings(),
        sourcePath: null,
    );

    expect($container->mounts())->toBe([
        [
            'source' => '/etc/orbit',
            'target' => '/etc/orbit',
            'read_only' => true,
        ],
    ]);
});

it('scopes the runtime container to the websocket node inside Docker E2E', function (): void {
    $previousNetwork = getenv('ORBIT_E2E_DOCKER_NETWORK');
    $previousNodeContainer = getenv('ORBIT_NODE_CONTAINER');

    putenv('ORBIT_E2E_DOCKER_NETWORK=orbit-e2e-run-123');
    putenv('ORBIT_NODE_CONTAINER=orbit-e2e-run-123-gateway');

    try {
        $node = websocketRuntimeNode([
            'name' => 'app-dev-1',
            'host' => 'dev',
        ]);

        $container = websocketRuntimeRenderer()->render($node, websocketRuntimeSettings());

        expect($container->name())->toBe('orbit-e2e-run-123-dev-orbit-websocket-app-dev-1')
            ->and($container->networkAliases())->toContain('orbit-e2e-run-123-dev-orbit-websocket-app-dev-1')
            ->and($container->network())->toBe('orbit-e2e-run-123');
    } finally {
        if ($previousNetwork === false) {
            putenv('ORBIT_E2E_DOCKER_NETWORK');
        } else {
            putenv("ORBIT_E2E_DOCKER_NETWORK={$previousNetwork}");
        }

        if ($previousNodeContainer === false) {
            putenv('ORBIT_NODE_CONTAINER');
        } else {
            putenv("ORBIT_NODE_CONTAINER={$previousNodeContainer}");
        }
    }
});

it('exposes labels with the spec hash and websocket backend identity', function (): void {
    $container = websocketRuntimeRenderer()->render(
        websocketRuntimeNode(),
        websocketRuntimeSettings(),
    );

    expect($container->labels())->toMatchArray([
        'orbit.managed' => 'true',
        'orbit.container.kind' => 'websocket-runtime',
        'orbit.websocket.backend' => '10.6.0.44',
    ])
        ->and($container->labels()[WebSocketRuntimeContainer::SpecHashLabel] ?? null)->toBe($container->specHash());
});

it('renders docker run with the private Reverb bind environment and shell command', function (): void {
    $container = websocketRuntimeRenderer()->render(
        websocketRuntimeNode(),
        websocketRuntimeSettings(),
    );

    $command = (new DockerCommandBuilder)->runDetached($container);

    expect($command)->toContain("--env 'REVERB_SERVER_HOST=10.6.0.44'")
        ->and($command)->toContain("--entrypoint 'sh'")
        ->and($command)->toContain("'-lc' 'php artisan reverb:start --host=10.6.0.44 --port=8080 --hostname=10.6.0.44'")
        ->and($command)->not->toContain('.websocket.orbit')
        ->and($command)->not->toContain('0.0.0.0');
});

it('changes the spec hash when the selected Redis node changes', function (): void {
    $node = websocketRuntimeNode();

    $redisOne = websocketRuntimeRenderer()->render($node, websocketRuntimeSettings(websocketRuntimeRedisNode([
        'name' => 'redis-1',
        'wireguard_address' => '10.6.0.3',
    ])));
    $redisTwo = websocketRuntimeRenderer()->render($node, websocketRuntimeSettings(websocketRuntimeRedisNode([
        'name' => 'redis-2',
        'wireguard_address' => '10.6.0.4',
    ])));

    expect($redisOne->specHash())->not->toBe($redisTwo->specHash());
});

it('throws when the websocket node has no WireGuard address', function (): void {
    $node = websocketRuntimeNode(['wireguard_address' => null]);

    expect(fn () => websocketRuntimeRenderer()->env($node, websocketRuntimeSettings()))
        ->toThrow(RuntimeException::class, 'The websocket role requires a WireGuard address before runtime config can be rendered.');
});

it('throws when the configured Redis node is unavailable', function (): void {
    $node = websocketRuntimeNode();

    expect(fn () => websocketRuntimeRenderer()->env($node, new WebSocketRoleSettings(redisNodeId: 1234)))
        ->toThrow(RuntimeException::class, 'The websocket role requires an active Redis node before runtime config can be rendered.');
});

it('throws when the configured Redis node has no WireGuard address', function (): void {
    $node = websocketRuntimeNode();
    $redisNode = websocketRuntimeRedisNode(['wireguard_address' => null]);

    $exception = null;

    try {
        websocketRuntimeRenderer()->env($node, websocketRuntimeSettings($redisNode));
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($exception?->getMessage())->toContain('redis-1')
        ->and($exception?->getMessage())->toContain('redis');
});
