<?php

declare(strict_types=1);

use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Runtime\OrbitGatewayContainer;
use App\Services\Runtime\OrbitGatewayContainerManager;
use App\Services\Runtime\OrbitGatewayContainerRenderer;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

function gatewayContainerForManagerTest(): OrbitGatewayContainer
{
    return (new OrbitGatewayContainerRenderer(new OrbitContainerNames))->render(
        orbitCheckoutPath: '/home/orbit/orbit',
        gatewayConfigRoot: '/home/orbit/.config/orbit',
    );
}

function gatewayContainerInspectPayload(OrbitGatewayContainer $container, bool $running = true, ?string $specHash = null): string
{
    return json_encode([
        'State' => [
            'Running' => $running,
        ],
        'Config' => [
            'Labels' => [
                OrbitGatewayContainer::SpecHashLabel => $specHash ?? $container->specHash(),
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

it('creates the runtime network and container when they are absent', function (): void {
    $container = gatewayContainerForManagerTest();
    $builder = new DockerCommandBuilder;

    Process::fake([
        $builder->networkInspect($container->network()) => Process::result(exitCode: 1),
        $builder->networkCreate($container->network()) => Process::result(),
        $builder->containerInspect($container->name()) => Process::result(exitCode: 1),
        $builder->runDetached($container) => Process::result(),
    ]);

    (new OrbitGatewayContainerManager($builder))->apply($container);

    Process::assertRan($builder->networkCreate($container->network()));
    Process::assertRan($builder->runDetached($container));
});

it('does not recreate a running container that already matches the rendered spec', function (): void {
    $container = gatewayContainerForManagerTest();
    $builder = new DockerCommandBuilder;

    Process::fake([
        $builder->networkInspect($container->network()) => Process::result(),
        $builder->containerInspect($container->name()) => Process::result(
            output: gatewayContainerInspectPayload($container),
        ),
    ]);

    (new OrbitGatewayContainerManager($builder))->apply($container);

    Process::assertNotRan($builder->networkCreate($container->network()));
    Process::assertNotRan($builder->containerRemove($container->name()));
    Process::assertNotRan($builder->runDetached($container));
    Process::assertNotRan($builder->containerStart($container->name()));
});

it('starts an existing matching container when it is stopped', function (): void {
    $container = gatewayContainerForManagerTest();
    $builder = new DockerCommandBuilder;

    Process::fake([
        $builder->networkInspect($container->network()) => Process::result(),
        $builder->containerInspect($container->name()) => Process::result(
            output: gatewayContainerInspectPayload($container, running: false),
        ),
        $builder->containerStart($container->name()) => Process::result(),
    ]);

    (new OrbitGatewayContainerManager($builder))->apply($container);

    Process::assertRan($builder->containerStart($container->name()));
    Process::assertNotRan($builder->containerRemove($container->name()));
    Process::assertNotRan($builder->runDetached($container));
});

it('recreates an existing container when the rendered spec drifts', function (): void {
    $container = gatewayContainerForManagerTest();
    $builder = new DockerCommandBuilder;

    Process::fake([
        $builder->networkInspect($container->network()) => Process::result(),
        $builder->containerInspect($container->name()) => Process::result(
            output: gatewayContainerInspectPayload($container, specHash: 'old-spec'),
        ),
        $builder->containerRemove($container->name()) => Process::result(),
        $builder->runDetached($container) => Process::result(),
    ]);

    (new OrbitGatewayContainerManager($builder))->apply($container);

    Process::assertRan($builder->containerRemove($container->name()));
    Process::assertRan($builder->runDetached($container));
});
