<?php

declare(strict_types=1);

use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmManager;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('initializes Docker Swarm when the local node is inactive', function (): void {
    Process::fake([
        "docker info --format '{{.Swarm.LocalNodeState}}'" => Process::result(output: "inactive\n"),
        'docker swarm init' => Process::result(),
    ]);

    (new GatewaySwarmManager)->ensureSwarm();

    Process::assertRan('docker swarm init');
});

it('does not initialize Docker Swarm when it is already active', function (): void {
    Process::fake([
        "docker info --format '{{.Swarm.LocalNodeState}}'" => Process::result(output: "active\n"),
    ]);

    (new GatewaySwarmManager)->ensureSwarm();

    Process::assertNotRan('docker swarm init');
});

it('labels the gateway Swarm node', function (): void {
    Process::fake([
        "docker info --format '{{.Swarm.NodeID}}'" => Process::result(output: "node-123\n"),
        "docker node update --label-add 'orbit.role.gateway=true' 'node-123'" => Process::result(),
    ]);

    (new GatewaySwarmManager)->ensureGatewayNodeLabel();

    Process::assertRan("docker node update --label-add 'orbit.role.gateway=true' 'node-123'");
});

it('labels the local Swarm node for colocated gateway vpn and dns services', function (): void {
    Process::fake([
        "docker info --format '{{.Swarm.NodeID}}'" => Process::result(output: "node-123\n"),
        "docker node update --label-add 'orbit.role.gateway=true' --label-add 'orbit.role.vpn=true' --label-add 'orbit.role.dns=true' 'node-123'" => Process::result(),
    ]);

    (new GatewaySwarmManager)->ensureGatewayEdgeNodeLabels();

    Process::assertRan("docker node update --label-add 'orbit.role.gateway=true' --label-add 'orbit.role.vpn=true' --label-add 'orbit.role.dns=true' 'node-123'");
});

it('creates an attachable overlay network when orbit-network is absent', function (): void {
    Process::fake([
        "docker network inspect --format '{{.Driver}} {{.Scope}} {{.Attachable}}' 'orbit-network'" => Process::result(exitCode: 1),
        "docker network create --driver overlay --attachable 'orbit-network'" => Process::result(),
    ]);

    (new GatewaySwarmManager)->ensureAttachableOverlayNetwork();

    Process::assertRan("docker network create --driver overlay --attachable 'orbit-network'");
});

it('reuses an existing attachable Swarm overlay network', function (): void {
    Process::fake([
        "docker network inspect --format '{{.Driver}} {{.Scope}} {{.Attachable}}' 'orbit-network'" => Process::result(output: "overlay swarm true\n"),
    ]);

    (new GatewaySwarmManager)->ensureAttachableOverlayNetwork();

    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'docker network create'));
});

it('rejects a legacy bridge orbit-network instead of silently reusing it', function (): void {
    Process::fake([
        "docker network inspect --format '{{.Driver}} {{.Scope}} {{.Attachable}}' 'orbit-network'" => Process::result(output: "bridge local false\n"),
    ]);

    expect(fn () => (new GatewaySwarmManager)->ensureAttachableOverlayNetwork())
        ->toThrow(RuntimeException::class, 'Existing Docker network [orbit-network] is not an attachable Swarm overlay. Run the explicit Orbit network migration before enabling gateway Swarm services.');
});

it('writes stack files under the configured config root', function (): void {
    $root = sys_get_temp_dir().'/orbit-swarm-manager-'.bin2hex(random_bytes(6));

    try {
        $path = (new GatewaySwarmManager(configRoot: $root))->writeStackFile("services: {}\n");

        expect($path)->toBe($root.'/swarm/orbit-gateway-stack.yml')
            ->and($path)->toBeFile()
            ->and(file_get_contents($path))->toBe("services: {}\n");
    } finally {
        (new SymfonyProcess(['rm', '-rf', $root]))->run();
    }
});

it('deploys a stack file with docker stack deploy', function (): void {
    Process::fake([
        "docker stack deploy -c '/tmp/orbit-stack.yml' 'orbit'" => Process::result(),
    ]);

    (new GatewaySwarmManager)->deployStack('/tmp/orbit-stack.yml');

    Process::assertRan("docker stack deploy -c '/tmp/orbit-stack.yml' 'orbit'");
});

it('updates and scales Swarm services using the planned command shapes', function (): void {
    $image = GatewayImageReference::fromString('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    Process::fake([
        "docker service update --detach=true --image 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'" => Process::result(),
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.2@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\n"),
        "docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'" => Process::result(output: "1/1\n"),
        "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(output: "completed\n"),
    ]);

    $manager = new GatewaySwarmManager;
    $manager->updateServiceImage('orbit_orbit-gateway', $image, 'start-first');
    $manager->scaleService('orbit_orbit-scheduler', 0);

    expect($manager->serviceImage('orbit_orbit-scheduler'))
        ->toBe('ghcr.io/hardimpactdev/orbit-gateway:1.2.2@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
        ->and($manager->serviceReplicas('orbit_orbit-scheduler'))->toBe('1/1')
        ->and($manager->serviceUpdateState('orbit_orbit-gateway'))->toBe('completed');

    Process::assertRan("docker service update --detach=true --image 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'");
    Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=0'");
    Process::assertRan("docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'");
    Process::assertRan("docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'");
});

it('scales Swarm services without waiting for Docker stability verification', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $command = (string) $process->command;

        if (str_starts_with($command, 'docker service scale')) {
            $commands[] = $command;
        }

        return Process::result();
    });

    (new GatewaySwarmManager)->scaleService('orbit_orbit-scheduler', 0);

    expect($commands)->toBe([
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'",
    ]);
});

it('updates Swarm service images without waiting for Docker stability verification', function (): void {
    $commands = [];
    $image = GatewayImageReference::fromString('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

    Process::fake(function ($process) use (&$commands) {
        $command = (string) $process->command;

        if (str_starts_with($command, 'docker service update')) {
            $commands[] = $command;
        }

        return Process::result();
    });

    (new GatewaySwarmManager)->updateServiceImage('orbit_orbit-gateway', $image, 'start-first');

    expect($commands)->toBe([
        "docker service update --detach=true --image 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'",
    ]);
});
