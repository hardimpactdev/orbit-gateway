<?php

declare(strict_types=1);

use App\Services\Runtime\OrbitContainerNames;
use App\Services\Runtime\OrbitGatewayContainer;
use App\Services\Runtime\OrbitGatewayContainerRenderer;
use Tests\TestCase;

uses(TestCase::class);

it('renders the Orbit gateway container with deterministic network, env, restart policy, and mounts', function (): void {
    $container = (new OrbitGatewayContainerRenderer(new OrbitContainerNames))->render(
        orbitCheckoutPath: '/Users/nckrtl/Orbit Repo',
        image: 'orbit-gateway:test',
        environment: [
            'APP_ENV' => 'local',
        ],
    );

    expect($container->name())->toBe('orbit-gateway')
        ->and($container->image())->toBe('orbit-gateway:test')
        ->and($container->network())->toBe('orbit-network')
        ->and($container->restartPolicy())->toBe('unless-stopped')
        ->and($container->networkAliases())->toBe(['orbit-gateway'])
        ->and($container->environment())->toBe([
            'APP_ENV' => 'local',
            'ORBIT_SOURCE_PATH' => OrbitGatewayContainer::SourcePath,
        ])
        ->and($container->mounts())->toContain([
            'source' => '/Users/nckrtl/Orbit Repo',
            'target' => OrbitGatewayContainer::SourcePath,
            'read_only' => false,
        ])
        ->and($container->mounts())->toContain([
            'source' => '/var/run/docker.sock',
            'target' => '/var/run/docker.sock',
            'read_only' => false,
        ])
        ->and($container->mounts())->not->toContain([
            'source' => '/Users/nckrtl/.config/orbit/gateway.sqlite',
            'target' => '/home/orbit/.config/orbit/gateway.sqlite',
            'read_only' => false,
        ]);
});

it('renders the gateway config root bind mount without gateway self identity env', function (): void {
    $container = (new OrbitGatewayContainerRenderer(new OrbitContainerNames))->render(
        orbitCheckoutPath: '/home/orbit/orbit',
        gatewayConfigRoot: '/home/orbit/.config/orbit',
    );

    expect($container->environment())->toMatchArray([
        'ORBIT_CONFIG_ROOT' => '/home/orbit/.config/orbit',
        'ORBIT_SOURCE_PATH' => OrbitGatewayContainer::SourcePath,
        'ORBIT_TRUST_WIREGUARD_PROXY_HEADER' => '1',
    ])
        ->and($container->mounts())->toContain([
            'source' => '/home/orbit/.config/orbit',
            'target' => '/home/orbit/.config/orbit',
            'read_only' => false,
        ])
        ->and($container->mounts())->not->toContain([
            'source' => '/home/orbit/.config/orbit/gateway.sqlite',
            'target' => '/home/orbit/.config/orbit/gateway.sqlite',
            'read_only' => false,
        ]);
});
