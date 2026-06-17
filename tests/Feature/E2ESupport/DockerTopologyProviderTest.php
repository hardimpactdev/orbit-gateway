<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\DockerTopologyNetworkPlan;
use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2EResourceLeasePool;
use App\E2E\Support\E2ETopologyAcquisitionOptions;
use App\E2E\Support\E2ETopologyAcquisitionRetainedForDiagnosis;
use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\SshKeyPair;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();

    putenv('ORBIT_E2E_DOCKER_TEST_RUNNERS=local:8:64,beast:8:64,sidecar1:8:64,sidecar2:8:64');
    putenv('ORBIT_E2E_DOCKER_SOURCE_PATH');
    putenv('ORBIT_E2E_DOCKER_SOURCE_PATH_BEAST');
    $this->dockerLeaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));
    putenv("ORBIT_E2E_LEASE_DIRECTORY={$this->dockerLeaseDirectory}");
    putenv('ORBIT_E2E_SLOT_WAIT_SECONDS=0');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dockerLeaseDirectory));
    putenv('ORBIT_E2E_DOCKER_TEST_RUNNERS');
    putenv('ORBIT_E2E_DOCKER_SOURCE_PATH');
    putenv('ORBIT_E2E_DOCKER_SOURCE_PATH_BEAST');
    putenv('ORBIT_E2E_LEASE_DIRECTORY');
    putenv('ORBIT_E2E_SLOT_WAIT_SECONDS');
    putenv('GH_TOKEN');
    putenv('GITHUB_TOKEN');
});

it('runs docker exec for instance commands', function (): void {
    Process::fake([
        "docker exec 'orbit-e2e-run-operator' sh -lc *" => Process::result(output: "ok\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-operator');

    $result = $instance->exec('echo ok');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("ok\n");
});

it('maps ssh transport to user-scoped docker exec for container feature topologies', function (): void {
    Process::fake([
        "docker exec --user 'orbit' 'orbit-e2e-run-operator' sh -lc *" => Process::result(output: "orbit\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-operator');

    $result = $instance->ssh('orbit', new SshKeyPair('/tmp/fake', '/tmp/fake.pub'), 'whoami');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("orbit\n");
});

it('passes GitHub auth variable names into docker feature topology commands without embedding token values', function (): void {
    putenv('GH_TOKEN=ghp_docker_secret');
    putenv('GITHUB_TOKEN');

    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result(output: "ok\n");
    });

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-operator');

    $instance->ssh('orbit', new SshKeyPair('/tmp/fake', '/tmp/fake.pub'), 'whoami');

    expect($commands[0])
        ->toContain("--env 'GH_TOKEN'")
        ->toContain("--env 'GITHUB_TOKEN'")
        ->toContain("docker exec --env 'GH_TOKEN' --env 'GITHUB_TOKEN' --user 'orbit'")
        ->not->toContain('ghp_docker_secret');
});

it('reads ipv4 from the named docker network only', function (): void {
    Process::fake([
        "docker inspect -f '{{(index .NetworkSettings.Networks \"orbit-e2e-run\").IPAddress}}' 'orbit-e2e-run-operator'" => Process::result(output: "10.6.0.3\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-operator', 'orbit-e2e-run');

    expect($instance->waitForIpv4())->toBe('10.6.0.3');
});

it('starts Docker client topology nodes without a runtime sibling container', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $timer = new E2EPhaseTimer;
    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $lease = $provider->acquire(E2ETopologyKind::Operator, 'run123', $timer, new E2ETopologyAcquisitionOptions);

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('--group-add "$(stat -c %g /var/run/docker.sock 2>/dev/null || stat -f %g /var/run/docker.sock)"')
        ->toContain("--volume '/var/run/docker.sock:/var/run/docker.sock'")
        ->toContain("--mount 'type=volume,src=orbit-e2e-run123-operator-home-orbit,dst=/home/orbit'")
        ->toContain("--env 'ORBIT_E2E_DOCKER_NETWORK=orbit-e2e-run123'")
        ->toContain("--env 'ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit'")
        ->toContain('ip addr add')
        ->toContain('10.6.0.3/24')
        ->not->toContain('ORBIT_GATEWAY_CONTAINER=orbit-e2e-run123-operator-orbit-gateway')
        ->not->toContain("docker run -d --restart unless-stopped --name 'orbit-e2e-run123-operator-orbit-gateway'")
        ->not->toContain("--mount 'type=bind,src=".repo_path().",dst=/home/orbit/orbit'")
        ->not->toContain('/home/operator');

    expect(array_column($timer->events(), 'name'))->not->toContain('docker.source-sync');

    $lease->cleanup();
});

it('marks Docker source-dev acquisitions with the mounted checkout path', function (): void {
    Process::fake(function ($process) {
        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $preparedLease = $provider->acquire(E2ETopologyKind::Operator, 'prepared123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);
    $sourceDevLease = $provider->acquire(E2ETopologyKind::Operator, 'source123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions(sourceMountedCheckout: true));

    expect($preparedLease->operator())->toBeInstanceOf(DockerInstance::class)
        ->and($preparedLease->operator()->sourceMountedCheckoutPath())->toBeNull()
        ->and($sourceDevLease->operator())->toBeInstanceOf(DockerInstance::class)
        ->and($sourceDevLease->operator()->sourceMountedCheckoutPath())->toBe('/home/orbit/orbit');

    $preparedLease->cleanup();
    $sourceDevLease->cleanup();
});

it('does not use host PHP or host Caddy paths while starting Docker gateway API support', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $lease = $provider->acquire(
        E2ETopologyKind::OperatorGateway,
        'run123',
        new E2EPhaseTimer,
        new E2ETopologyAcquisitionOptions(startGatewayApi: true),
    );

    $setup = implode("\n", $commands);
    $gatewayRuntimeStart = array_find_key(
        $commands,
        fn (string $command): bool => str_contains($command, "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-gateway'"),
    );
    $gatewayScheduler = array_find_key(
        $commands,
        fn (string $command): bool => str_contains($command, "docker exec --detach --workdir '/home/orbit/orbit' 'orbit-e2e-run123-gateway-orbit-gateway' orbit orbit-scheduler"),
    );
    $gatewayCertificate = array_find_key(
        $commands,
        fn (string $command): bool => str_contains($command, 'issueLeaf'),
    );
    $gatewayConfigRepairAfterCertificate = array_find_key(
        $commands,
        fn (string $command, int $index): bool => is_int($gatewayCertificate)
            && $index > $gatewayCertificate
            && str_contains($command, 'chown -R orbit:orbit')
            && str_contains($command, '/home/orbit/.config/orbit'),
    );

    expect($gatewayRuntimeStart)->toBeInt()
        ->and($gatewayScheduler)->toBeInt()
        ->and($gatewayCertificate)->toBeInt()
        ->and($gatewayConfigRepairAfterCertificate)->toBeInt()
        ->and($gatewayRuntimeStart)->toBeLessThan($gatewayScheduler)
        ->and($gatewayScheduler)->toBeLessThan($gatewayCertificate)
        ->and($gatewayCertificate)->toBeLessThan($gatewayConfigRepairAfterCertificate);

    expect($setup)
        ->toContain('php apps/gateway/artisan tinker --execute=')
        ->toContain('php -d display_errors=0 -d max_execution_time=0 -S')
        ->toContain('PHP_CLI_SERVER_WORKERS=4')
        ->toContain("'orbit-gateway:prepared-current' tail -f /dev/null")
        ->toContain("docker exec --detach --workdir '/home/orbit/orbit' 'orbit-e2e-run123-gateway-orbit-gateway' orbit orbit-scheduler")
        ->not->toContain('orbit serve --host=')
        ->not->toContain('php artisan')
        ->not->toContain('nohup php')
        ->not->toContain('php -r')
        ->not->toContain('systemctl stop caddy');

    $lease->cleanup();
});

it('reports docker unavailable when docker is missing', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(exitCode: 1),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->availability(E2ETopologyKind::Operator)->available)->toBeFalse();
});

it('reports docker unavailable when prepared per-role image is missing', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-gateway:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'caddy:2-alpine' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e:operator_base' >/dev/null" => Process::result(exitCode: 1),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
    $availability = $provider->availability(E2ETopologyKind::Operator);

    expect($availability->available)->toBeFalse()
        ->and($availability->message)->toContain('orbit-e2e:operator_base');
});

it('falls back from namespaced docker role images to base images per role', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $command = (string) $process->command;
        $commands[] = $command;

        if ($command === "docker image inspect 'orbit-e2e:operator_branch-a-b' >/dev/null") {
            return Process::result(exitCode: 1);
        }

        if ($command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || $command === "docker image inspect 'orbit-gateway:branch-a-b-current' >/dev/null"
            || $command === "docker image inspect 'caddy:2-alpine' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:operator_base' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:gateway_branch-a-b' >/dev/null"
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || str_starts_with($command, 'docker network create ')
            || str_starts_with($command, 'docker exec ')
        ) {
            return Process::result();
        }

        if (str_starts_with($command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Branch A/B',
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:4',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

        expect($lease->operator()->name())->toBe('orbit-e2e-run123-operator')
            ->and($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway');

        $lease->cleanup();
    });

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain("docker image inspect 'orbit-e2e:operator_branch-a-b' >/dev/null")
        ->toContain("docker image inspect 'orbit-e2e:operator_base' >/dev/null")
        ->toContain("docker image inspect 'orbit-e2e:gateway_branch-a-b' >/dev/null")
        ->not->toContain("docker image inspect 'orbit-e2e:gateway_base' >/dev/null");

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "docker run -d --name 'orbit-e2e-run123-operator'")
        && str_contains((string) $process->command, "'orbit-e2e:operator_base'"));
    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, "docker run -d --name 'orbit-e2e-run123-gateway'")
        && str_contains((string) $process->command, "'orbit-e2e:gateway_branch-a-b'"));
});

it('requires the orbit gateway sibling image only for gateway-backed Docker topology', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null' || $process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if ($process->command === "docker image inspect 'orbit-gateway:prepared-current' >/dev/null") {
            return Process::result(exitCode: 1);
        }

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result();
        }

        return Process::result();
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
    $operatorAvailability = $provider->availability(E2ETopologyKind::Operator);
    $gatewayAvailability = $provider->availability(E2ETopologyKind::OperatorGateway);

    expect($operatorAvailability->available)->toBeTrue()
        ->and($gatewayAvailability->available)->toBeFalse()
        ->and($gatewayAvailability->message)->toContain('orbit-gateway:prepared-current');
});

it('requires every supported FrankenPHP image for app-node Docker topologies', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null' || $process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if ($process->command === "docker image inspect 'dunglas/frankenphp:1-php8.4-bookworm' >/dev/null") {
            return Process::result(exitCode: 1);
        }

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result();
        }

        return Process::result();
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
    $operatorGatewayAvailability = $provider->availability(E2ETopologyKind::OperatorGateway);
    $appDevAvailability = $provider->availability(E2ETopologyKind::OperatorGatewayAppdev);

    expect($operatorGatewayAvailability->available)->toBeTrue()
        ->and($appDevAvailability->available)->toBeFalse()
        ->and($appDevAvailability->message)->toContain('dunglas/frankenphp:1-php8.4-bookworm');
});

it('advertises container feature capabilities', function (): void {
    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->capabilities())->toEqual(E2ETopologyCapabilities::containerFeature());
});

it('advertises sibling container Docker support', function (): void {
    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->capabilities()->dockerSiblingContainers)->toBeTrue();
});

it('targets remote docker hosts through docker host environment', function (): void {
    $seenEnvironment = null;

    Process::fake(function ($process) use (&$seenEnvironment) {
        $seenEnvironment = $process->environment;

        return Process::result();
    });

    (new DockerHost(E2EConfig::fromEnvironment(), 'beast'))->run('docker info >/dev/null');

    expect($seenEnvironment)->toMatchArray(['DOCKER_HOST' => 'ssh://beast']);
});

it('selects the first docker test runner with image availability', function (): void {
    Process::fake(function ($process) {
        $host = $process->environment['DOCKER_HOST'] ?? 'local';

        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_contains($process->command, 'docker image inspect') && $host === 'ssh://beast') {
            return Process::result(exitCode: 1);
        }

        if (str_contains($process->command, 'docker image inspect')) {
            return Process::result();
        }

        if ($process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'") {
            return $host === 'ssh://beast'
                ? Process::result(output: "orbit-e2e-a\norbit-e2e-b\n")
                : Process::result(output: '');
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:1:2,local:1:2',
        'ORBIT_E2E_DOCKER_SOURCE_PATH' => '/srv/orbit-source',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $availability = $provider->availability(E2ETopologyKind::Operator);

        expect($availability->available)->toBeTrue()
            ->and($availability->message)->toContain('local');
    });
});

it('keeps remote docker runners available without a preconfigured source path', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null'
            || $process->command === 'docker info >/dev/null'
            || str_starts_with($process->command, 'docker image inspect ')
            || str_starts_with($process->command, 'docker ps ')) {
            return Process::result();
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:1:64',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
        $availability = $provider->availability(E2ETopologyKind::Operator);

        expect($availability->available)->toBeTrue()
            ->and($availability->message)->toContain('beast');
    });
});

it('selects the next docker test runner when the first runner is missing images', function (): void {
    Process::fake(function ($process) {
        $host = $process->environment['DOCKER_HOST'] ?? 'local';

        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_contains($process->command, 'docker image inspect') && $host === 'ssh://sidecar1') {
            return Process::result(exitCode: 1);
        }

        if (str_contains($process->command, 'docker image inspect')) {
            return Process::result();
        }

        if ($process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'") {
            return match ($host) {
                'ssh://sidecar1' => Process::result(output: "orbit-e2e-a\n"),
                'ssh://beast' => Process::result(output: "orbit-e2e-a\norbit-e2e-b\n"),
                default => Process::result(output: ''),
            };
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:1:1,beast:1:3',
        'ORBIT_E2E_DOCKER_SOURCE_PATH' => '/srv/orbit-source',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $availability = $provider->availability(E2ETopologyKind::Operator);

        expect($availability->available)->toBeTrue()
            ->and($availability->message)->toContain('beast');
    });
});

it('allows slow remote docker metadata probes during host selection', function (): void {
    $probeTimeouts = [];

    Process::fake(function ($process) use (&$probeTimeouts) {
        if ($process->command === 'docker info >/dev/null' || str_starts_with($process->command, 'docker image inspect ') || str_starts_with($process->command, 'docker ps ')) {
            $probeTimeouts[$process->command] = $process->timeout;
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:1:64',
        'ORBIT_E2E_DOCKER_SOURCE_PATH' => '/srv/orbit-source',
        'ORBIT_E2E_TIMEOUT_SECONDS' => '600',
    ], function () use (&$probeTimeouts): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        expect($provider->availability(E2ETopologyKind::Operator)->available)->toBeTrue()
            ->and($probeTimeouts['docker info >/dev/null'])->toBe(120)
            ->and($probeTimeouts["docker image inspect 'orbit-e2e:operator_base' >/dev/null"])->toBe(120);
    });
});

it('uses the configured remote docker source path for source-mounted bind mounts', function (): void {
    $commands = [];
    $timer = new E2EPhaseTimer;

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if ($process->command === 'command -v docker >/dev/null'
            || $process->command === 'docker info >/dev/null'
            || str_starts_with($process->command, 'docker image inspect ')
            || $process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || str_starts_with($process->command, 'docker network create ')
            || str_starts_with($process->command, 'docker exec ')
            || str_starts_with($process->command, 'ssh -o BatchMode=yes -o ConnectTimeout=10 ')
            || str_starts_with($process->command, 'rsync -az --delete ')) {
            return Process::result();
        }

        if (str_starts_with($process->command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:1:64',
        'ORBIT_E2E_DOCKER_SOURCE_PATH' => '/srv/global-orbit-source',
        'ORBIT_E2E_DOCKER_SOURCE_PATH_BEAST' => '/srv/orbit-source',
    ], function () use ($timer): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(
            E2ETopologyKind::Operator,
            'run123',
            $timer,
            new E2ETopologyAcquisitionOptions(sourceMountedCheckout: true),
        );

        $lease->cleanup();
    });

    expect(implode("\n", $commands))
        ->toContain("--mount 'type=bind,src=/srv/orbit-source,dst=/home/orbit/orbit'");

    expect(array_column($timer->events(), 'name'))->toContain('docker.source-sync');
});

it('counts running docker containers with the configured e2e instance prefix', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_contains($process->command, 'docker image inspect')) {
            return Process::result();
        }

        if ($process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-custom-'") {
            return Process::result(output: "orbit-custom-a\norbit-custom-b\n");
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_INSTANCE_PREFIX' => 'orbit-custom',
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:2',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        expect(fn () => $provider->acquire(E2ETopologyKind::Operator, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
            ->toThrow(RuntimeException::class, 'docker capacity exceeded');
    });
});

it('accounts for the gateway sibling container when checking docker capacity', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null' || $process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result();
        }

        if ($process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'") {
            return Process::result(output: "orbit-e2e-running-operator\n");
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:3',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        expect(fn () => $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
            ->toThrow(RuntimeException::class, 'docker capacity exceeded');
    });
});

it('fails websocket docker topology availability when the reverb runtime image is missing', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'command -v docker >/dev/null' || $process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if ($process->command === "docker image inspect 'orbit-reverb:current' >/dev/null") {
            return Process::result(exitCode: 1);
        }

        if (str_starts_with($process->command, 'docker image inspect ')) {
            return Process::result();
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:8:64',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
        $availability = $provider->availability(E2ETopologyKind::OperatorGatewayAppdevWebsocket);

        expect($availability->available)->toBeFalse()
            ->and($availability->message)->toContain('Docker websocket image orbit-reverb:current is not available');
    });
});

it('does not fail availability on transient docker capacity when host slots are configured', function (): void {
    $probedCapacity = false;

    Process::fake(function ($process) use (&$probedCapacity) {
        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_contains($process->command, 'docker image inspect')) {
            return Process::result();
        }

        if (str_starts_with($process->command, 'docker ps ')) {
            $probedCapacity = true;

            return Process::result(output: "orbit-e2e-a\norbit-e2e-b\n");
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:1:1',
        'ORBIT_E2E_DOCKER_SOURCE_PATH' => '/srv/orbit-source',
    ], function () use (&$probedCapacity): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
        $availability = $provider->availability(E2ETopologyKind::OperatorGateway);

        expect($availability->available)->toBeTrue()
            ->and($availability->message)->toContain('sidecar1')
            ->and($probedCapacity)->toBeFalse();
    });
});

it('acquires an operator-gateway lease by launching containers from prepared images', function (): void {
    Process::fake(function ($process) {
        $command = $process->command;

        if (
            $command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || str_starts_with($command, 'docker image inspect ')
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || (str_starts_with($command, "docker network create --subnet '10.") && str_ends_with($command, "'orbit-e2e-run123'"))
            || str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-operator' ")
            || str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-gateway' ")
            || str_starts_with($command, "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-gateway' ")
            || str_starts_with($command, 'docker exec ')
        ) {
            return str_contains($command, 'docker run -d ')
                ? Process::result(output: "container-id\n")
                : Process::result();
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $lease = $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

    expect($lease->operator()->name())->toBe('orbit-e2e-run123-operator')
        ->and($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway');

    $lease->cleanup();
});

it('prepares source mounted gateway state before seeding docker gateway records', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $command = (string) $process->command;
        $commands[] = $command;

        if (
            $command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || str_starts_with($command, 'docker image inspect ')
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || (str_starts_with($command, "docker network create --subnet '10.") && str_ends_with($command, "'orbit-e2e-run123'"))
            || str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-operator' ")
            || str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-gateway' ")
            || str_starts_with($command, "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-gateway' ")
            || str_starts_with($command, 'docker exec ')
        ) {
            return str_contains($command, 'docker run -d ')
                ? Process::result(output: "container-id\n")
                : Process::result();
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
    $lease = $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);
    $setup = implode("\n", $commands);

    $runtimeStart = array_find_key(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-gateway'"),
    );
    $stateBootstrap = array_find_key(
        $commands,
        fn (string $command): bool => str_contains($command, '/home/orbit/.config/orbit/gateway.sqlite'),
    );
    $stateMigrate = array_find_key(
        $commands,
        fn (string $command): bool => str_contains($command, 'php apps/gateway/artisan migrate --force --no-interaction --ansi'),
    );
    $scheduler = array_find_key(
        $commands,
        fn (string $command): bool => $command === "docker exec --detach --workdir '/home/orbit/orbit' 'orbit-e2e-run123-gateway-orbit-gateway' orbit orbit-scheduler",
    );
    $seedOperatorIdentity = array_find_key(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec 'orbit-e2e-run123-gateway' sh -lc")
            && str_contains($command, 'sudo -iu orbit env')
            && str_contains($command, 'DB_DATABASE="${DB_DATABASE:-/home/orbit/.config/orbit/gateway.sqlite}"')
            && str_contains($command, 'cd /home/orbit/orbit && export ORBIT_CONFIG_ROOT=')
            && str_contains($command, 'base64_decode'),
    );
    $operatorClientGateway = array_find_key(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec 'orbit-e2e-run123-operator' sh -lc")
            && str_contains($command, 'ORBIT_GATEWAY_URL=%s')
            && str_contains($command, 'http://gateway')
            && str_contains($command, '/home/orbit/.config/orbit/config.json'),
    );

    expect($runtimeStart)->toBeInt()
        ->and($stateBootstrap)->toBeInt()
        ->and($stateMigrate)->toBeInt()
        ->and($scheduler)->toBeInt()
        ->and($operatorClientGateway)->toBeInt()
        ->and($seedOperatorIdentity)->toBeInt()
        ->and($runtimeStart)->toBeLessThan($stateBootstrap)
        ->and($runtimeStart)->toBeLessThan($operatorClientGateway)
        ->and($operatorClientGateway)->toBeLessThan($seedOperatorIdentity)
        ->and($stateBootstrap)->toBeLessThan($scheduler)
        ->and($scheduler)->toBeLessThan($seedOperatorIdentity)
        ->and($stateBootstrap)->toBeLessThan($seedOperatorIdentity)
        ->and($stateMigrate)->toBeLessThan($seedOperatorIdentity);

    $stateCommand = $commands[$stateBootstrap];
    $appKey = strpos($stateCommand, 'php apps/gateway/artisan key:generate --force --no-interaction');
    $migrate = strpos($stateCommand, 'php apps/gateway/artisan migrate --force --no-interaction --ansi');

    expect($appKey)->toBeInt()
        ->and($migrate)->toBeInt()
        ->and($appKey)->toBeLessThan($migrate);

    expect($setup)
        ->toContain('ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit')
        ->toContain('/home/orbit/.config/orbit/gateway.sqlite')
        ->not->toContain('apps/gateway/database/database.sqlite');

    $lease->cleanup();
});

it('reuses image resolution from host selection when starting docker containers', function (): void {
    $imageInspectCounts = [];

    Process::fake(function ($process) use (&$imageInspectCounts) {
        if ($process->command === 'command -v docker >/dev/null'
            || $process->command === 'docker info >/dev/null'
            || $process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || str_starts_with($process->command, 'docker network create ')
            || str_starts_with($process->command, 'docker run -d ')
            || str_starts_with($process->command, 'docker exec ')
        ) {
            return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
        }

        if (str_starts_with($process->command, 'docker image inspect ')) {
            $imageInspectCounts[$process->command] = ($imageInspectCounts[$process->command] ?? 0) + 1;

            return Process::result();
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $lease = $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

    expect($imageInspectCounts["docker image inspect 'orbit-e2e:operator_base' >/dev/null"])->toBe(1)
        ->and($imageInspectCounts["docker image inspect 'orbit-e2e:gateway_base' >/dev/null"])->toBe(1);

    $lease->cleanup();
});

it('retries run-scoped docker subnets when Docker reports an overlap', function (): void {
    withE2EEnvironment(['TEST_TOKEN'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:8:64',
    ], function (): void {
        $commands = [];
        $firstPlan = DockerTopologyNetworkPlan::fromEnvironment('run123');
        $retryPlan = DockerTopologyNetworkPlan::fromEnvironment('run123', attempt: 1);

        Process::fake(function ($process) use (&$commands, $firstPlan, $retryPlan) {
            $command = (string) $process->command;
            $commands[] = $command;

            if ($command === 'command -v docker >/dev/null'
                || $command === 'docker info >/dev/null'
                || str_starts_with($command, 'docker image inspect ')
                || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
                || str_starts_with($command, 'docker exec ')
            ) {
                return Process::result();
            }

            if ($command === "docker network create --subnet '{$firstPlan->subnet()}' 'orbit-e2e-run123'") {
                return Process::result(errorOutput: 'Error response from daemon: invalid pool request: Pool overlaps with other one on this address space', exitCode: 1);
            }

            if ($command === "docker network create --subnet '{$retryPlan->subnet()}' 'orbit-e2e-run123'") {
                return Process::result();
            }

            if (str_starts_with($command, 'docker run -d ')) {
                return Process::result(output: "container-id\n");
            }

            return Process::result(exitCode: 1, errorOutput: $command);
        });

        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

        expect($lease->gatewayApiIp())->toBe($retryPlan->ipForRole('gateway'))
            ->and($commands)->toContain("docker network create --subnet '{$firstPlan->subnet()}' 'orbit-e2e-run123'")
            ->and($commands)->toContain("docker network create --subnet '{$retryPlan->subnet()}' 'orbit-e2e-run123'");

        $lease->cleanup();
    });
});

it('launches operator-gateway from the prepared base image', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $command = (string) $process->command;
        $commands[] = $command;

        if (str_contains($command, 'cat ~/.ssh/id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        if ($command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || $command === "docker image inspect 'orbit-gateway:prepared-current' >/dev/null"
            || $command === "docker image inspect 'caddy:2-alpine' >/dev/null"
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || str_starts_with($command, 'docker network create ')
            || str_starts_with($command, 'docker exec ')
        ) {
            return Process::result();
        }

        if (str_contains($command, 'operator_base')
            || str_contains($command, 'gateway_base')) {
            return Process::result();
        }

        if (str_starts_with($command, 'docker image inspect ')) {
            return Process::result(exitCode: 1);
        }

        if (str_starts_with($command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:4',
    ], function () use (&$commands): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

        expect($lease->operator()->name())->toBe('orbit-e2e-run123-operator')
            ->and($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway')
            ->and($lease->devApp())->toBeNull()
            ->and($lease->prodApp())->toBeNull();

        $lease->cleanup();
    });

    $setup = implode("\n", $commands);

    expect($setup)->toContain("docker image inspect 'orbit-e2e:operator_base' >/dev/null")
        ->and($setup)->toContain("docker image inspect 'orbit-e2e:gateway_base' >/dev/null")
        ->and($setup)->toContain('orbit-e2e:operator_base')
        ->and($setup)->toContain('orbit-e2e:gateway_base')
        ->and($setup)->not->toContain('orbit-e2e-run123-dev')
        ->and($setup)->not->toContain('orbit-e2e-run123-prod')
        ->and($setup)->not->toContain('orbit-e2e-run123-agent');
});

it('launches app production ingress as a prod-node role', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $command = (string) $process->command;
        $commands[] = $command;

        if (str_contains($command, 'cat ~/.ssh/id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        if ($command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || $command === "docker image inspect 'orbit-gateway:prepared-current' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e-topology-runtime:prepared-current' >/dev/null"
            || $command === "docker image inspect 'caddy:2-alpine' >/dev/null"
            || $command === "docker image inspect 'dunglas/frankenphp:1-php8.5-bookworm' >/dev/null"
            || $command === "docker image inspect 'dunglas/frankenphp:1-php8.4-bookworm' >/dev/null"
            || $command === "docker image inspect 'dunglas/frankenphp:1-php8.3-bookworm' >/dev/null"
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || str_starts_with($command, 'docker network create ')
            || str_starts_with($command, 'docker exec ')
        ) {
            return Process::result();
        }

        if (str_contains($command, 'operator_base')
            || str_contains($command, 'gateway_base')
            || str_contains($command, 'app-prod_base')) {
            return Process::result();
        }

        if (str_starts_with($command, 'docker image inspect ')) {
            return Process::result(exitCode: 1);
        }

        if (str_starts_with($command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:6',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(
            E2ETopologyKind::OperatorGatewayAppprodIngress,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions(startGatewayApi: true),
        );

        expect($lease->operator()->name())->toBe('orbit-e2e-run123-operator')
            ->and($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway')
            ->and($lease->prodApp()?->name())->toBe('orbit-e2e-run123-prod')
            ->and($lease->ingress()?->name())->toBe('orbit-e2e-run123-prod')
            ->and($lease->instanceNames())->toBe([
                'orbit-e2e-run123-operator',
                'orbit-e2e-run123-gateway',
                'orbit-e2e-run123-prod',
            ]);

        $lease->cleanup();
    });

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain("docker image inspect 'orbit-e2e:operator_base' >/dev/null")
        ->toContain("docker image inspect 'orbit-e2e:gateway_base' >/dev/null")
        ->toContain("docker image inspect 'orbit-e2e:app-prod_base' >/dev/null")
        ->toContain('orbit-e2e:app-prod_base')
        ->toContain("docker run -d --name 'orbit-e2e-run123-prod'")
        ->toContain('app-prod-1')
        ->not->toContain('orbit-e2e-run123-ingress')
        ->not->toContain('orbit-e2e-topology-runtime:prepared-current')
        ->not->toContain('orbit-e2e:operator_gateway_app-prod_ingress')
        ->not->toContain('edge-1');
});

it('seeds gateway registry rows for composed docker app roles at acquire time', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $command = (string) $process->command;
        $commands[] = $command;

        if (str_contains($command, 'cat ~/.ssh/id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        if ($command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || $command === "docker image inspect 'orbit-gateway:prepared-current' >/dev/null"
            || $command === "docker image inspect 'caddy:2-alpine' >/dev/null"
            || $command === "docker image inspect 'dunglas/frankenphp:1-php8.5-bookworm' >/dev/null"
            || $command === "docker image inspect 'dunglas/frankenphp:1-php8.4-bookworm' >/dev/null"
            || $command === "docker image inspect 'dunglas/frankenphp:1-php8.3-bookworm' >/dev/null"
            || $command === "docker image inspect 'orbit-reverb:current' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:operator_base' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:gateway_base' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:app-dev_base' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:app-prod_base' >/dev/null"
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || str_starts_with($command, 'docker network create ')
            || str_starts_with($command, 'docker exec ')
        ) {
            return Process::result();
        }

        if (str_starts_with($command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:8',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(
            E2ETopologyKind::OperatorGatewayAppdevAppprod,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions,
        );

        $lease->cleanup();
    });

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('orbit:internal:bake-app-node app-dev-1 --role=app-dev')
        ->toContain('--host=dev')
        ->toContain('--wireguard-address=10.6.0.4')
        ->toContain('--gateway-endpoint=gateway')
        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
        ->toContain('orbit:internal:bake-app-node app-prod-1 --role=app-prod')
        ->toContain('--wireguard-address=10.6.0.5')
        ->toContain('--ingress-node=app-prod-1');
});

it('registers websocket on the app-dev node for docker prepared topologies', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $command = (string) $process->command;
        $commands[] = $command;

        if (str_contains($command, 'cat ~/.ssh/id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        if ($command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || $command === "docker image inspect 'orbit-gateway:prepared-current' >/dev/null"
            || $command === "docker image inspect 'caddy:2-alpine' >/dev/null"
            || $command === "docker image inspect 'dunglas/frankenphp:1-php8.5-bookworm' >/dev/null"
            || $command === "docker image inspect 'dunglas/frankenphp:1-php8.4-bookworm' >/dev/null"
            || $command === "docker image inspect 'dunglas/frankenphp:1-php8.3-bookworm' >/dev/null"
            || $command === "docker image inspect 'orbit-reverb:current' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:operator_base' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:gateway_base' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:app-dev_base' >/dev/null"
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || str_starts_with($command, 'docker network create ')
            || str_starts_with($command, 'docker exec ')
        ) {
            return Process::result();
        }

        if (str_starts_with($command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:8',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(
            E2ETopologyKind::OperatorGatewayAppdevWebsocket,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions,
        );

        expect($lease->devApp()?->name())->toBe('orbit-e2e-run123-dev')
            ->and($lease->instance('websocket'))->toBeNull()
            ->and($lease->instanceNames())->toBe([
                'orbit-e2e-run123-operator',
                'orbit-e2e-run123-gateway',
                'orbit-e2e-run123-dev',
            ]);

        $lease->cleanup();
    });

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('orbit:internal:bake-app-node app-dev-1 --role=app-dev')
        ->toContain('orbit:internal:bake-websocket-node app-dev-1')
        ->toContain('--host=dev')
        ->toContain('--wireguard-address=10.6.0.4')
        ->toContain('--redis-node=app-dev-1')
        ->toContain('/home/orbit/.ssh/authorized_keys')
        ->not->toContain('--environment=');
});

it('authorizes the active docker gateway ssh key into composed app role containers', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $command = (string) $process->command;
        $commands[] = $command;

        if (str_contains($command, 'cat ~/.ssh/id_ed25519.pub')) {
            return Process::result(output: "ssh-ed25519 AAAATEST orbit-e2e-gateway\n");
        }

        if ($command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || $command === "docker image inspect 'orbit-gateway:prepared-current' >/dev/null"
            || $command === "docker image inspect 'caddy:2-alpine' >/dev/null"
            || $command === "docker image inspect 'dunglas/frankenphp:1-php8.5-bookworm' >/dev/null"
            || $command === "docker image inspect 'dunglas/frankenphp:1-php8.4-bookworm' >/dev/null"
            || $command === "docker image inspect 'dunglas/frankenphp:1-php8.3-bookworm' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:operator_base' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:gateway_base' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:app-dev_base' >/dev/null"
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || str_starts_with($command, 'docker network create ')
            || str_starts_with($command, 'docker exec ')
        ) {
            return Process::result();
        }

        if (str_starts_with($command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:8',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(
            E2ETopologyKind::OperatorGatewayAppdev,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions,
        );

        $lease->cleanup();
    });

    $setup = implode("\n", $commands);

    expect($setup)
        ->toContain('cat ~/.ssh/id_ed25519.pub')
        ->toContain('/home/orbit/.ssh/authorized_keys')
        ->toContain('ssh-ed25519 AAAATEST orbit-e2e-gateway');
});

it('maps gateway local orbit-gateway docker commands to the per-run runtime container', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $command = (string) $process->command;
        $commands[] = $command;

        if ($command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || $command === "docker image inspect 'orbit-gateway:prepared-current' >/dev/null"
            || $command === "docker image inspect 'caddy:2-alpine' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:operator_base' >/dev/null"
            || $command === "docker image inspect 'orbit-e2e:gateway_base' >/dev/null"
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || str_starts_with($command, 'docker network create ')
            || str_starts_with($command, 'docker exec ')
        ) {
            return Process::result();
        }

        if (str_starts_with($command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    withE2EEnvironment([], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:1:8',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(
            E2ETopologyKind::OperatorGateway,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions,
        );

        $lease->cleanup();
    });

    $shimCommand = collect($commands)
        ->first(fn (string $command): bool => str_contains($command, 'ORBIT_E2E_GATEWAY_DOCKER_SHIM')
            && str_contains($command, 'orbit-e2e-run123-gateway-orbit-gateway'));

    expect($shimCommand)
        ->toBeString()
        ->toContain('# ORBIT_E2E_GATEWAY_DOCKER_SHIM')
        ->toContain('gateway_container=')
        ->toContain('node_container=')
        ->toContain('orbit-e2e-run123-gateway-orbit-gateway')
        ->toContain('orbit-e2e-run123-gateway')
        ->toContain('${node_container}-home-orbit')
        ->toContain('${node_container}-etc-orbit')
        ->toContain('/usr/bin/docker.real')
        ->toContain('ORBIT_E2E_RUNTIME_DOCKER_SHIM')
        ->toContain('elif [ ! -x /usr/bin/docker.real ]; then')
        ->toContain('rewrite_mount')
        ->toContain('rewrite_volume')
        ->toContain('type=bind,source=*|type=bind,src=*)')
        ->toContain('/home/orbit/*)')
        ->toContain('source_path="${ORBIT_SOURCE_PATH:-/home/orbit/orbit}"')
        ->not->toContain('/proc/1/environ')
        ->toContain('orbit-gateway)')
        ->toContain('/opt/orbit/*)')
        ->toContain('exec "${real_docker}" "${args[@]}"');
});

it('uses the parallel worker token to create a non-overlapping docker network', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-gateway:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'caddy:2-alpine' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e:operator_base' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e:gateway_base' >/dev/null" => Process::result(),
        "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(),
        "docker network create --subnet * 'orbit-e2e-run123'" => Process::result(),
        "docker run -d --name 'orbit-e2e-run123-operator' *" => Process::result(output: "operator-id\n"),
        "docker run -d --name 'orbit-e2e-run123-gateway' *" => Process::result(output: "gateway-id\n"),
        "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-gateway' *" => Process::result(output: "runtime-id\n"),
        'docker exec *' => Process::result(),
        'docker rm -f *' => Process::result(),
        'docker volume rm -f *' => Process::result(),
        'docker network rm *' => Process::result(),
    ]);

    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=2');

    try {
        $networkPlan = DockerTopologyNetworkPlan::fromEnvironment('run123');
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
        $lease = $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

        expect($lease->operator()->name())->toBe('orbit-e2e-run123-operator')
            ->and($lease->gatewayApiIp())->toBe($networkPlan->ipForRole('gateway'));

        Process::assertRan("docker network create --subnet '{$networkPlan->subnet()}' 'orbit-e2e-run123'");

        $lease->cleanup();
    } finally {
        if ($previous === false) {
            putenv('TEST_TOKEN');
        } else {
            putenv("TEST_TOKEN={$previous}");
        }
    }
});

it('leases docker host slots independently from the parallel worker token', function (): void {
    $networkHost = null;
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    Process::fake(function ($process) use (&$networkHost) {
        $host = $process->environment['DOCKER_HOST'] ?? 'local';

        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_contains($process->command, 'docker image inspect')) {
            return Process::result();
        }

        if ($process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'") {
            return Process::result();
        }

        if (str_starts_with($process->command, "docker network create --subnet '10.90.")
            && str_contains($process->command, ".0/24'")) {
            $networkHost = $host;

            return Process::result();
        }

        if (str_starts_with($process->command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        if (str_starts_with($process->command, 'docker exec ')) {
            return Process::result();
        }

        if (str_starts_with($process->command, 'ssh -o BatchMode=yes -o ConnectTimeout=10 ')
            || str_starts_with($process->command, 'rsync -az --delete ')) {
            return Process::result();
        }

        return Process::result(exitCode: 1, errorOutput: $process->command);
    });

    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=5');

    try {
        withE2EConfigEnvironment([
            'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:2:64,sidecar2:2:64,beast:3:64',
            'ORBIT_E2E_DOCKER_SOURCE_PATH' => '/srv/orbit-source',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
        ], function () use (&$networkHost): void {
            $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

            $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

            expect($networkHost)->toBe('ssh://sidecar1');
        });
    } finally {
        exec('rm -rf '.escapeshellarg($leaseDirectory));

        if ($previous === false) {
            putenv('TEST_TOKEN');
        } else {
            putenv("TEST_TOKEN={$previous}");
        }
    }
});

it('releases docker host slots during topology cleanup', function (): void {
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    Process::fake([
        '*command -v docker*' => Process::result(),
        '*docker info*' => Process::result(),
        '*docker image inspect*' => Process::result(),
        '*docker ps*' => Process::result(),
        '*docker network create*' => Process::result(),
        '*docker run -d*' => Process::result(output: "container-id\n"),
        '*docker exec*' => Process::result(),
        '*docker rm -f*' => Process::result(),
        '*docker volume rm*' => Process::result(),
        '*docker network rm*' => Process::result(),
        '*ssh -o BatchMode=yes -o ConnectTimeout=10*' => Process::result(),
        '*rsync -az --delete*' => Process::result(),
    ]);

    try {
        withE2EConfigEnvironment([
            'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:1:64',
            'ORBIT_E2E_DOCKER_SOURCE_PATH' => '/srv/orbit-source',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
            'ORBIT_E2E_SLOT_WAIT_SECONDS' => '0',
        ], function () use ($leaseDirectory): void {
            $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
            $lease = $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);
            $pool = new E2EResourceLeasePool($leaseDirectory, waitSeconds: 0, staleSeconds: 60);

            expect($pool->snapshot('docker', ['sidecar1' => 1]))->toMatchArray([
                ['host' => 'sidecar1', 'slot' => 1, 'leased' => true],
            ]);

            $lease->cleanup();

            expect($pool->snapshot('docker', ['sidecar1' => 1]))->toMatchArray([
                ['host' => 'sidecar1', 'slot' => 1, 'leased' => false],
            ]);
        });
    } finally {
        exec('rm -rf '.escapeshellarg($leaseDirectory));
    }
});

it('keeps the docker network until final cleanup across fresh resets', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
    $lease = $provider->acquire(E2ETopologyKind::Operator, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

    $lease->reset();

    expect(collect($commands)->filter(fn (string $command): bool => str_starts_with($command, 'docker network create '))->count())->toBe(1)
        ->and(collect($commands)->contains("docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true"))->toBeFalse();

    $lease->cleanup();

    expect(collect($commands)->filter(fn (string $command): bool => $command === "docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true")->count())->toBe(1);
});

it('cleans containers and network when docker acquire fails partway through', function (): void {
    Process::fake(function ($process) {
        $command = $process->command;

        if (
            $command === 'command -v docker >/dev/null'
            || $command === 'docker info >/dev/null'
            || str_starts_with($command, 'docker image inspect ')
            || $command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'"
            || (str_starts_with($command, "docker network create --subnet '10.") && str_ends_with($command, "'orbit-e2e-run123'"))
            || $command === "docker rm -f 'orbit-e2e-run123-operator-orbit-caddy' 'orbit-e2e-run123-operator' 'orbit-e2e-run123-gateway-orbit-gateway' 'orbit-e2e-run123-gateway-orbit-caddy' 'orbit-e2e-run123-gateway' >/dev/null 2>&1 || true"
            || $command === "docker volume rm -f 'orbit-e2e-run123-operator-home-orbit' 'orbit-e2e-run123-gateway-home-orbit' >/dev/null 2>&1 || true"
            || $command === "docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true"
        ) {
            return Process::result();
        }

        if (str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-operator' ")) {
            return Process::result(output: "operator-id\n");
        }

        if (str_starts_with($command, "docker run -d --name 'orbit-e2e-run123-gateway' ")) {
            return Process::result(exitCode: 1, errorOutput: "failed\n");
        }

        return Process::result(exitCode: 1, errorOutput: $command);
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect(fn () => $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
        ->toThrow(RuntimeException::class, 'Could not start container');

    Process::assertRan("docker rm -f 'orbit-e2e-run123-operator-orbit-caddy' 'orbit-e2e-run123-operator' 'orbit-e2e-run123-gateway-orbit-gateway' 'orbit-e2e-run123-gateway-orbit-caddy' 'orbit-e2e-run123-gateway' >/dev/null 2>&1 || true");
    Process::assertRan("docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true");
});

it('retains docker acquisition failures for diagnosis when requested', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $command = $process->command;
        $commands[] = $command;

        if (str_contains($command, 'orbit:internal:bake-app-node app-dev-1')) {
            return Process::result(exitCode: 1, errorOutput: "bake failed\n");
        }

        return Process::result(output: str_starts_with($command, 'docker run -d ') ? "container-id\n" : '');
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    try {
        $provider->acquire(
            E2ETopologyKind::OperatorGatewayAppdev,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions(startGatewayApi: true, retainOnFailure: true),
        );

        $this->fail('Expected Docker acquisition to be retained after failure.');
    } catch (E2ETopologyAcquisitionRetainedForDiagnosis $exception) {
        expect($exception->provider)->toBe('docker')
            ->and($exception->host)->toBe('local')
            ->and($exception->runId)->toBe('run123')
            ->and($exception->network)->toBe('orbit-e2e-run123')
            ->and($exception->instances)->toMatchArray([
                'operator' => 'orbit-e2e-run123-operator',
                'gateway' => 'orbit-e2e-run123-gateway',
                'dev' => 'orbit-e2e-run123-dev',
            ])
            ->and($exception->managedContainers)->toContain('orbit-e2e-run123-dev-orbit-caddy')
            ->and($exception->volumes)->toContain('orbit-e2e-run123-dev-etc-caddy');
    }

    expect(implode("\n", $commands))
        ->not->toContain("docker rm -f 'orbit-e2e-run123-operator-orbit-caddy'")
        ->not->toContain("docker network rm 'orbit-e2e-run123'");
});

it('starts docker containers as a batch and rolls back when one start fails', function (): void {
    withE2EEnvironment(['ORBIT_E2E_DOCKER_PARALLEL_STARTS', 'TEST_TOKEN'], [
        'ORBIT_E2E_DOCKER_PARALLEL_STARTS' => '1',
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:8:64',
    ], function (): void {
        Process::fake([
            'command -v docker >/dev/null' => Process::result(),
            'docker info >/dev/null' => Process::result(),
            "docker image inspect 'orbit-gateway:prepared-current' >/dev/null" => Process::result(),
            "docker image inspect 'caddy:2-alpine' >/dev/null" => Process::result(),
            "docker image inspect 'orbit-e2e:operator_base' >/dev/null" => Process::result(),
            "docker image inspect 'orbit-e2e:gateway_base' >/dev/null" => Process::result(),
            "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(),
            "docker network create --subnet * 'orbit-e2e-run123'" => Process::result(),
            "docker run -d --name 'orbit-e2e-run123-operator' *" => Process::result(exitCode: 1, errorOutput: "operator failed\n"),
            "docker run -d --name 'orbit-e2e-run123-gateway' *" => Process::result(output: "gateway-id\n"),
            "docker rm -f 'orbit-e2e-run123-operator-orbit-caddy' 'orbit-e2e-run123-operator' 'orbit-e2e-run123-gateway-orbit-gateway' 'orbit-e2e-run123-gateway-orbit-caddy' 'orbit-e2e-run123-gateway' >/dev/null 2>&1 || true" => Process::result(),
            'docker volume rm -f *' => Process::result(),
            "docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true" => Process::result(),
        ]);

        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        expect(fn () => $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
            ->toThrow(RuntimeException::class, 'Could not start container orbit-e2e-run123-operator');

        Process::assertRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, "docker run -d --name 'orbit-e2e-run123-gateway'")
            && str_contains($process->command, '--group-add "$(stat -c %g /var/run/docker.sock 2>/dev/null || stat -f %g /var/run/docker.sock)"')
            && str_contains($process->command, "--volume '/var/run/docker.sock:/var/run/docker.sock'")
            && str_contains($process->command, "--env 'ORBIT_GATEWAY_CONTAINER=orbit-e2e-run123-gateway-orbit-gateway'")
            && str_contains($process->command, "'orbit-e2e:gateway_base'"));
        Process::assertRan("docker rm -f 'orbit-e2e-run123-operator-orbit-caddy' 'orbit-e2e-run123-operator' 'orbit-e2e-run123-gateway-orbit-gateway' 'orbit-e2e-run123-gateway-orbit-caddy' 'orbit-e2e-run123-gateway' >/dev/null 2>&1 || true");
    });
});

it('starts docker containers sequentially by default to avoid ssh startup bursts', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        if (str_contains($process->command, "docker run -d --name 'orbit-e2e-run123-operator'")) {
            return Process::result(exitCode: 1, errorOutput: "operator failed\n");
        }

        return Process::result(output: str_starts_with($process->command, 'docker run -d ') ? "container-id\n" : '');
    });

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect(fn () => $provider->acquire(E2ETopologyKind::OperatorGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
        ->toThrow(RuntimeException::class, 'Could not start container orbit-e2e-run123-operator');

    expect(implode("\n", $commands))->not->toContain("docker run -d --name 'orbit-e2e-run123-gateway'");
});

it('uses dns aliases and primes the gateway api in Docker topology runs', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-gateway:prepared-current' >/dev/null" => Process::result(),
        "docker image inspect 'caddy:2-alpine' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e:operator_base' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e:gateway_base' >/dev/null" => Process::result(),
        "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'" => Process::result(),
        "docker network create --subnet * 'orbit-e2e-run123'" => Process::result(),
        "docker run -d --name 'orbit-e2e-run123-operator' *" => Process::result(output: "operator-id\n"),
        "docker run -d --name 'orbit-e2e-run123-gateway' *" => Process::result(output: "gateway-id\n"),
        "docker run -d --restart unless-stopped --name 'orbit-e2e-run123-gateway-orbit-gateway' *" => Process::result(output: "runtime-id\n"),
        'docker exec *' => Process::result(),
        'docker rm -f *' => Process::result(),
        'docker volume rm -f *' => Process::result(),
        'docker network rm *' => Process::result(),
    ]);

    withE2EEnvironment(['TEST_TOKEN'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:8:64',
    ], function (): void {
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(
            E2ETopologyKind::OperatorGateway,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions(startGatewayApi: true),
        );

        expect($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway');

        $lease->cleanup();
    });

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'bootstrap-gateway-local')
        && str_contains($process->command, '/home/orbit/.config/orbit/gateway.sqlite'));
    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, 'issueLeaf')
        && str_contains($process->command, 'gateway')
        && str_contains($process->command, '10.6.0.2'));
});

it('maps parallel docker subnet peer ips back to canonical dns-alias identities', function (): void {
    $commands = [];
    $networkPlan = null;

    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result();
    });

    withE2EEnvironment(['TEST_TOKEN'], [
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'local:8:64',
        'TEST_TOKEN' => '1',
    ], function () use (&$networkPlan): void {
        $networkPlan = DockerTopologyNetworkPlan::fromEnvironment('run123');
        $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

        $lease = $provider->acquire(
            E2ETopologyKind::OperatorGateway,
            'run123',
            new E2EPhaseTimer,
            new E2ETopologyAcquisitionOptions(startGatewayApi: true),
        );

        $lease->cleanup();
    });

    expect($networkPlan)->toBeInstanceOf(DockerTopologyNetworkPlan::class)
        ->and($commands)->toContain("docker network create --subnet '{$networkPlan->subnet()}' 'orbit-e2e-run123'");
    expect(implode("\n", $commands))
        ->toContain('sudo docker exec --detach')
        ->toContain('php apps/gateway/artisan tinker --execute=')
        ->toContain('base64_decode')
        ->toContain('$peerIdentityMap = array')
        ->toContain($networkPlan->ipForRole('operator'))
        ->toContain('10.6.0.3')
        ->toContain($networkPlan->ipForRole('gateway'))
        ->toContain('10.6.0.2')
        ->toContain('127.0.0.1')
        ->toContain('::1');
});
