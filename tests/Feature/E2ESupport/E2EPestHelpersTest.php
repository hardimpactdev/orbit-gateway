<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ETopologyCache;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Mockery as m;

afterEach(function (): void {
    E2ETopologyCache::flushForTests(cleanup: false);
    putenv('ORBIT_E2E_TIMINGS');
    m::close();
});

it('loads e2e pest helper functions from the pest bootstrap', function (): void {
    expect(function_exists('e2eTopology'))->toBeTrue()
        ->and(function_exists('e2eCheckout'))->toBeTrue();
});

it('keeps pest helpers scoped to prepared topology acquisition', function (): void {
    $helpers = file_get_contents(repo_path('apps/gateway/tests/E2E/Support/Pest.php'));

    expect($helpers)
        ->toContain('function e2eTopology(')
        ->toContain('E2ETopologyFactory::fromEnvironment()')
        ->toContain('function e2eTopologyCleanup(')
        ->not->toContain('e2eProvisionOperatorFromBase')
        ->not->toContain('e2eProvisionGatewayThroughNodeNew')
        ->not->toContain('e2eProvisionAppThroughNodeNew')
        ->not->toContain('node:new')
        ->not->toContain("'--role=app',")
        ->not->toContain("'--environment='");
});

it('builds provider aware current checkout orbit wrappers', function (): void {
    $docker = e2eOrbitWrapperScript('/home/orbit/orbit-current', dockerRuntime: true, executorNodeIdentity: 'app-dev-1', hostLauncher: true);
    $dockerRuntime = e2eOrbitWrapperScript('/home/orbit/orbit-current', dockerRuntime: true);
    $incusHostLauncher = e2eOrbitWrapperScript('/home/orbit/orbit-current', dockerRuntime: false, executorNodeIdentity: 'gateway', hostLauncher: true);
    $incusGatewayArtisan = e2eOrbitWrapperScript('/home/orbit/orbit-current', dockerRuntime: false);

    expect($docker)
        ->toContain('ORBIT_REPO="${checkout}"')
        ->toContain('exec "${checkout}/bin/orbit" "$@"')
        ->not->toContain('sudo docker exec')
        ->not->toContain('apps/gateway/artisan')
        ->and($dockerRuntime)
        ->toContain('sudo docker exec')
        ->toContain('ORBIT_SOURCE_PATH=/home/orbit/orbit-current')
        ->toContain('runtime_workdir="${ORBIT_HOST_CWD:-$PWD}"')
        ->toContain('--workdir "${runtime_workdir}"')
        ->not->toContain('exec php')
        ->toContain("php '/home/orbit/orbit-current/apps/gateway/artisan' \"\$@\"")
        ->and($incusHostLauncher)
        ->toContain('ORBIT_REPO="${checkout}"')
        ->toContain('exec "${checkout}/bin/orbit" "$@"')
        ->not->toContain('apps/gateway/artisan')
        ->not->toContain('sudo docker exec')
        ->and($incusGatewayArtisan)
        ->toContain("exec php '/home/orbit/orbit-current/apps/gateway/artisan'")
        ->not->toContain('sudo docker exec');
});

it('runs provider aware runtime commands through Docker runtime siblings', function (): void {
    Process::fake(['*' => Process::result()]);
    Process::preventStrayProcesses();

    $commands = [];
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGateway,
        operator: e2ePestFakeInstance($commands, 'operator'),
        gateway: new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-gateway', 'orbit-e2e-run'),
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
        rebuild: fn () => throw new RuntimeException('not expected'),
    ));

    e2eRunInRoleRuntime($harness, 'gateway', e2ePhpServerCommand(
        port: 48123,
        routerPath: '/tmp/router.php',
        logPath: '/tmp/router.log',
        pidPath: '/tmp/router.pid',
    ));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, "docker exec 'orbit-e2e-run-gateway' sh -lc")
        && str_contains($process->command, 'orbit-e2e-run-gateway-orbit-gateway')
        && str_contains($process->command, 'php -S 127.0.0.1:48123'));
});

it('uses Docker runtime siblings only for gateway roles', function (): void {
    $commands = [];
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdev,
        operator: e2ePestFakeInstance($commands, 'operator'),
        gateway: new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-gateway', 'orbit-e2e-run'),
        dev: new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-dev', 'orbit-e2e-run'),
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
        rebuild: fn () => throw new RuntimeException('not expected'),
    ));

    expect(e2eRoleUsesDockerRuntime($harness, 'gateway'))->toBeTrue()
        ->and(e2eRoleUsesDockerRuntime($harness, 'dev'))->toBeFalse();
});

it('wraps a topology lease with checkout and ssh helpers', function (): void {
    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $operator = e2ePestFakeInstance($commands, 'operator');

    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: $operator,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    ));

    $harness->setCheckouts(['operator' => '/home/orbit/orbit-current']);

    $result = $harness->ssh('operator', 'php artisan node:list --json');

    expect($result->successful())->toBeTrue();
    expect($commands)->toContain('ssh:orbit:php artisan node:list --json');
    expect($harness->checkout('operator'))->toBe('/home/orbit/orbit-current');
});

it('can expose checkout paths through the e2eCheckout helper', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $operator = e2ePestFakeInstance($commands, 'operator');

    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: $operator,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    ));

    expect(e2eCheckout($harness, roles: ['operator']))->toBe(['operator' => '/home/orbit/orbit-current']);
});

it('clears checkout paths when the harness resets', function (): void {
    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $operator = e2ePestFakeInstance($commands, 'operator');

    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: $operator,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => [
            'instances' => ['operator' => $operator],
            'snapshotReset' => null,
        ],
    ));

    $harness->setCheckouts(['operator' => '/home/orbit/orbit-current']);
    $harness->reset();

    expect(fn () => $harness->checkout('operator'))
        ->toThrow(RuntimeException::class, 'Current checkout has not been installed');
});

it('fails clearly when a helper role is unavailable', function (): void {
    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: e2ePestFakeInstance($commands, 'operator'),
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    ));

    expect(fn () => $harness->instance('gateway'))
        ->toThrow(RuntimeException::class, 'Topology does not include role [gateway]');
});

it('can share cached topologies across helper calls in one process', function (): void {
    $previousCache = getenv('ORBIT_E2E_TOPOLOGY_CACHE');

    putenv('ORBIT_E2E_TOPOLOGY_CACHE=process');

    $created = 0;
    $deleted = 0;

    E2ETopologyCache::fakeResolver(function () use (&$created, &$deleted): E2ETopologyLease {
        $created++;
        $operator = e2ePestDeletableFakeInstance($deleted, 'operator');
        $gatewayCommands = [];

        return new E2ETopologyLease(
            kind: E2ETopologyKind::OperatorGatewayAppdevAppprod,
            operator: $operator,
            gateway: e2ePestFakeInstance($gatewayCommands, 'gateway'),
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
            rebuild: fn () => throw new RuntimeException('not expected'),
        );
    });

    try {
        $first = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod);
        $second = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod);

        expect($first->lease())->toBe($second->lease())
            ->and($created)->toBe(1);

        $first->cleanup();
        $second->cleanup();

        expect($deleted)->toBe(0);

        E2ETopologyCache::cleanup();

        expect($deleted)->toBe(1);
    } finally {
        if ($previousCache === false) {
            putenv('ORBIT_E2E_TOPOLOGY_CACHE');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_CACHE={$previousCache}");
        }

    }
});

it('keeps source-mounted and prepared topology cache entries separate', function (): void {
    $created = 0;
    $deleted = 0;
    $sourceMountedModes = [];

    E2ETopologyCache::fakeResolver(function (E2ETopologyKind $kind, ?array $sshUsers, bool $withGatewayApi, bool $sourceMountedCheckout) use (&$created, &$deleted, &$sourceMountedModes): E2ETopologyLease {
        $created++;
        $sourceMountedModes[] = $sourceMountedCheckout;

        return new E2ETopologyLease(
            kind: $kind,
            operator: e2ePestDeletableFakeInstance($deleted, $sourceMountedCheckout ? 'source-dev-operator' : 'prepared-operator'),
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
            rebuild: fn () => throw new RuntimeException('not expected'),
        );
    });

    $prepared = E2ETopologyCache::acquire(E2ETopologyKind::Operator);
    $sourceDev = E2ETopologyCache::acquire(E2ETopologyKind::Operator, sourceMountedCheckout: true);
    $sourceDevAgain = E2ETopologyCache::acquire(E2ETopologyKind::Operator, sourceMountedCheckout: true);

    expect($prepared->lease())->not->toBe($sourceDev->lease())
        ->and($sourceDev->lease())->toBe($sourceDevAgain->lease())
        ->and($created)->toBe(2)
        ->and($sourceMountedModes)->toBe([false, true]);

    E2ETopologyCache::cleanup();

    expect($deleted)->toBe(2);
});

it('evicts cached topologies when the process cache limit is reached', function (): void {
    $previousCache = getenv('ORBIT_E2E_TOPOLOGY_CACHE');
    $previousLimit = getenv('ORBIT_E2E_TOPOLOGY_CACHE_LIMIT');

    putenv('ORBIT_E2E_TOPOLOGY_CACHE=process');
    putenv('ORBIT_E2E_TOPOLOGY_CACHE_LIMIT=1');

    $created = 0;
    $deleted = 0;

    E2ETopologyCache::fakeResolver(function (E2ETopologyKind $kind) use (&$created, &$deleted): E2ETopologyLease {
        $created++;

        return new E2ETopologyLease(
            kind: $kind,
            operator: e2ePestDeletableFakeInstance($deleted, $kind->value.'-operator'),
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
            rebuild: fn () => throw new RuntimeException('not expected'),
        );
    });

    try {
        $first = e2eTopology(E2ETopologyKind::Operator);
        $second = e2eTopology(E2ETopologyKind::OperatorGateway);
        $third = e2eTopology(E2ETopologyKind::OperatorGateway);

        expect($first->lease())->not->toBe($second->lease())
            ->and($second->lease())->toBe($third->lease())
            ->and($created)->toBe(2)
            ->and($deleted)->toBe(1);

        E2ETopologyCache::cleanup();

        expect($deleted)->toBe(2);
    } finally {
        if ($previousCache === false) {
            putenv('ORBIT_E2E_TOPOLOGY_CACHE');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_CACHE={$previousCache}");
        }

        if ($previousLimit === false) {
            putenv('ORBIT_E2E_TOPOLOGY_CACHE_LIMIT');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_CACHE_LIMIT={$previousLimit}");
        }

    }
});

it('does not eagerly attach a timer to harnesses returned by the e2eTopology helper cache path', function (): void {
    $previousCache = getenv('ORBIT_E2E_TOPOLOGY_CACHE');
    putenv('ORBIT_E2E_TOPOLOGY_CACHE=process');

    E2ETopologyCache::fakeResolver(function (): E2ETopologyLease {
        $commands = [];

        return new E2ETopologyLease(
            kind: E2ETopologyKind::Operator,
            operator: e2ePestFakeInstance($commands, 'operator'),
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
            rebuild: fn () => throw new RuntimeException('not expected'),
        );
    });

    try {
        $harness = e2eTopology(E2ETopologyKind::Operator);

        expect(e2ePestHarnessTimer($harness))->toBeNull();
    } finally {
        if ($previousCache === false) {
            putenv('ORBIT_E2E_TOPOLOGY_CACHE');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_CACHE={$previousCache}");
        }
    }
});

it('does not eagerly attach a timer to cached topology harnesses returned directly from the cache', function (): void {
    E2ETopologyCache::fakeResolver(function (): E2ETopologyLease {
        $commands = [];

        return new E2ETopologyLease(
            kind: E2ETopologyKind::Operator,
            operator: e2ePestFakeInstance($commands, 'operator'),
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
            rebuild: fn () => throw new RuntimeException('not expected'),
        );
    });

    $harness = E2ETopologyCache::acquire(E2ETopologyKind::Operator);

    expect(e2ePestHarnessTimer($harness))->toBeNull();
});

it('creates and uses a checkout timer lazily when ORBIT_E2E_TIMINGS is enabled', function (): void {
    $previousCache = getenv('ORBIT_E2E_TOPOLOGY_CACHE');
    $previousTimings = getenv('ORBIT_E2E_TIMINGS');
    putenv('ORBIT_E2E_TOPOLOGY_CACHE=process');
    putenv('ORBIT_E2E_TIMINGS=1');
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    E2ETopologyCache::fakeResolver(function (): E2ETopologyLease {
        $commands = [];

        return new E2ETopologyLease(
            kind: E2ETopologyKind::Operator,
            operator: e2ePestFakeInstance($commands, 'operator'),
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
            rebuild: fn () => throw new RuntimeException('not expected'),
        );
    });

    try {
        $harness = e2eTopology(E2ETopologyKind::Operator);

        expect(e2ePestHarnessTimer($harness))->toBeNull();

        $harness->withCurrentCheckout(['operator']);

        $timer = e2ePestHarnessTimer($harness);

        expect($timer)->not->toBeNull()
            ->and($harness->checkouts())->toBe(['operator' => '/home/orbit/orbit-current']);
    } finally {
        if ($previousCache === false) {
            putenv('ORBIT_E2E_TOPOLOGY_CACHE');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_CACHE={$previousCache}");
        }

        if ($previousTimings === false) {
            putenv('ORBIT_E2E_TIMINGS');
        } else {
            putenv("ORBIT_E2E_TIMINGS={$previousTimings}");
        }
    }
});

it('restarts dns alias gateway api with canonical peer identity mapping', function (): void {
    $previousProvider = getenv('ORBIT_E2E_TOPOLOGY_PROVIDER');
    putenv('ORBIT_E2E_TOPOLOGY_PROVIDER=docker');

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdev,
        operator: e2ePestFakeInstanceWithIp($commands, 'operator', '10.61.0.3'),
        gateway: e2ePestFakeInstanceWithIp($commands, 'gateway', '10.61.0.2'),
        dev: e2ePestFakeInstanceWithIp($commands, 'dev', '10.61.0.4'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
    ));
    $harness->setCheckouts(['gateway' => '/home/orbit/orbit-current']);

    try {
        e2eRestartGatewayApi($harness, 'dns-alias-restart');

        expect(implode("\n", $commands))
            ->toContain('$peerIdentityMap = array')
            ->toContain('gateway')
            ->toContain('10.61.0.2')
            ->toContain('10.6.0.2')
            ->toContain('10.61.0.3')
            ->toContain('10.6.0.3')
            ->toContain('10.61.0.4')
            ->toContain('10.6.0.4');
    } finally {
        $previousProvider === false
            ? putenv('ORBIT_E2E_TOPOLOGY_PROVIDER')
            : putenv("ORBIT_E2E_TOPOLOGY_PROVIDER={$previousProvider}");
    }
});

it('does not remap a colocated ingress instance away from its node identity', function (): void {
    $commands = [];
    $prod = e2ePestFakeInstanceWithIp($commands, 'prod', '10.61.0.5');

    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppprodIngress,
        operator: e2ePestFakeInstanceWithIp($commands, 'operator', '10.61.0.3'),
        gateway: e2ePestFakeInstanceWithIp($commands, 'gateway', '10.61.0.2'),
        dev: null,
        prod: $prod,
        sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
        ingress: $prod,
    ));

    expect(e2eDockerDnsAliasPeerIdentityMap($harness))
        ->toHaveKey('127.0.0.1', '10.6.0.2')
        ->toHaveKey('::1', '10.6.0.2')
        ->toHaveKey('10.61.0.5', '10.6.0.5')
        ->not->toContain('10.6.0.7');
});

it('uses gateway dns identity for docker dns-alias gateway settings', function (): void {
    $previousProvider = getenv('ORBIT_E2E_TOPOLOGY_PROVIDER');
    putenv('ORBIT_E2E_TOPOLOGY_PROVIDER=docker');

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdev,
        operator: e2ePestFakeInstanceWithIp($commands, 'operator', '10.61.0.3'),
        gateway: e2ePestFakeInstanceWithIp($commands, 'gateway', '10.61.0.2'),
        dev: e2ePestFakeInstanceWithIp($commands, 'dev', '10.61.0.4'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
    ));

    try {
        expect(e2eGatewayApiUrl($harness))->toBe('https://gateway')
            ->and(e2eGatewayWireGuardIp($harness))->toBe('10.6.0.2');
    } finally {
        $previousProvider === false
            ? putenv('ORBIT_E2E_TOPOLOGY_PROVIDER')
            : putenv("ORBIT_E2E_TOPOLOGY_PROVIDER={$previousProvider}");
    }
});

it('uses lease gateway ip for incus gateway settings', function (): void {
    $previousProvider = getenv('ORBIT_E2E_TOPOLOGY_PROVIDER');
    putenv('ORBIT_E2E_TOPOLOGY_PROVIDER=incus');

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdev,
        operator: e2ePestFakeInstanceWithIp($commands, 'operator', '10.61.0.3'),
        gateway: e2ePestFakeInstanceWithIp($commands, 'gateway', '10.61.0.2'),
        dev: e2ePestFakeInstanceWithIp($commands, 'dev', '10.61.0.4'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
    ));

    try {
        expect(e2eGatewayApiUrl($harness))->toBe('https://10.61.0.2')
            ->and(e2eGatewayWireGuardIp($harness))->toBe('10.61.0.2');
    } finally {
        $previousProvider === false
            ? putenv('ORBIT_E2E_TOPOLOGY_PROVIDER')
            : putenv("ORBIT_E2E_TOPOLOGY_PROVIDER={$previousProvider}");
    }
});

it('uses lease gateway identity when restarting the incus gateway api', function (): void {
    $previousProvider = getenv('ORBIT_E2E_TOPOLOGY_PROVIDER');
    putenv('ORBIT_E2E_TOPOLOGY_PROVIDER=incus');

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAgent,
        operator: e2ePestFakeInstanceWithIp($commands, 'operator', '10.61.0.3'),
        gateway: e2ePestFakeInstanceWithIp($commands, 'gateway', '10.61.0.2'),
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
        agent: e2ePestFakeInstanceWithIp($commands, 'agent', '10.61.0.9'),
    ));
    $harness->setCheckouts(['gateway' => '/home/orbit/orbit-current']);

    try {
        e2eRestartGatewayApi($harness, 'incus-restart');
        $commandOutput = str_replace("'\\''", "'", implode("\n", $commands));

        expect(e2eGatewayApiUrl($harness))->toBe('https://10.61.0.2')
            ->and(e2eGatewayWireGuardIp($harness))->toBe('10.61.0.2')
            ->and($commandOutput)->toContain('10.61.0.2:80')
            ->toContain('$bindAddress = \'10.61.0.2\';')
            ->toContain('$certKey = \'10.61.0.2\';')
            ->not->toContain('0.0.0.0:80')
            ->not->toContain('$bindAddress = \'0.0.0.0\';')
            ->not->toContain('$certKey = \'gateway\';');
    } finally {
        $previousProvider === false
            ? putenv('ORBIT_E2E_TOPOLOGY_PROVIDER')
            : putenv("ORBIT_E2E_TOPOLOGY_PROVIDER={$previousProvider}");
    }
});

it('seeds current-checkout gateway settings for operator callers', function (): void {
    $previous = getenv('ORBIT_E2E_TOPOLOGY_PROVIDER');
    putenv('ORBIT_E2E_TOPOLOGY_PROVIDER');

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdev,
        operator: e2ePestFakeInstanceWithIp($commands, 'operator', '10.61.0.3'),
        gateway: e2ePestFakeInstanceWithIp($commands, 'gateway', '10.61.0.2'),
        dev: e2ePestFakeInstanceWithIp($commands, 'dev', '10.61.0.4'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
    ));
    $harness->setCheckouts(['operator' => '/home/operator/orbit-current']);

    try {
        e2eConfigureCurrentCheckoutGatewaySettings($harness);

        expect($commands)->toHaveCount(1)
            ->and($commands[0])->toContain("cd '/home/operator/orbit-current' && php apps/gateway/artisan tinker --execute=")
            ->and($commands[0])->toContain('LocalGatewaySettings::current()')
            ->and($commands[0])->toContain('gateway_url')
            ->and($commands[0])->toContain('https://10.61.0.2')
            ->and($commands[0])->toContain('gateway_wg_ip')
            ->and($commands[0])->toContain('10.61.0.2')
            ->and($commands[0])->not->toContain("cd '/home/operator/orbit' &&");
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_TOPOLOGY_PROVIDER')
            : putenv("ORBIT_E2E_TOPOLOGY_PROVIDER={$previous}");
    }
});

it('seeds docker app current-checkout gateway settings through the cli env and root settings', function (): void {
    $previous = getenv('ORBIT_E2E_TOPOLOGY_PROVIDER');
    putenv('ORBIT_E2E_TOPOLOGY_PROVIDER=docker');

    $dockerCommands = [];
    Process::fake(function ($process) use (&$dockerCommands): ProcessResult {
        $dockerCommands[] = (string) $process->command;

        return Process::result();
    });
    Process::preventStrayProcesses();

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdev,
        operator: e2ePestFakeInstance($commands, 'operator'),
        gateway: e2ePestFakeInstance($commands, 'gateway'),
        dev: new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-dev', 'orbit-e2e-run'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
    ));
    $harness->setCheckouts(['dev' => '/home/orbit/orbit']);

    try {
        e2eConfigureCurrentCheckoutGatewaySettings($harness, 'dev');

        expect($dockerCommands)->toHaveCount(2)
            ->and($dockerCommands[0])->toContain("docker exec --user 'orbit' 'orbit-e2e-run-dev' sh -lc")
            ->and($dockerCommands[0])->toContain('/home/orbit/orbit/apps/cli')
            ->and($dockerCommands[0])->toContain('tmp="$(mktemp)"')
            ->and($dockerCommands[0])->toContain('sudo install -m 0664')
            ->and($dockerCommands[0])->toContain('ORBIT_GATEWAY_URL')
            ->and($dockerCommands[0])->toContain('ORBIT_GATEWAY_IDENTITY')
            ->and($dockerCommands[0])->toContain('http://gateway')
            ->and($dockerCommands[0])->not->toContain('.env.tmp')
            ->and($dockerCommands[0])->not->toContain('php apps/gateway/artisan tinker --execute')
            ->and($dockerCommands[0])->not->toContain('LocalGatewaySettings::current()')
            ->and($dockerCommands[1])->toContain("docker exec --user 'orbit' 'orbit-e2e-run-dev' sh -lc")
            ->and($dockerCommands[1])->toContain('/home/orbit/orbit')
            ->and($dockerCommands[1])->toContain('php apps/gateway/artisan tinker --execute')
            ->and($dockerCommands[1])->toContain('LocalGatewaySettings::current()')
            ->and($dockerCommands[1])->toContain('https://gateway')
            ->and($dockerCommands[1])->toContain('http://gateway/api/ca/root')
            ->and($dockerCommands[1])->toContain('ca_sha256')
            ->and($dockerCommands[1])->toContain('ca_pem_path');
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_TOPOLOGY_PROVIDER')
            : putenv("ORBIT_E2E_TOPOLOGY_PROVIDER={$previous}");
    }
});

it('seeds docker operator current-checkout gateway settings through the cli env and root settings', function (): void {
    $previous = getenv('ORBIT_E2E_TOPOLOGY_PROVIDER');
    putenv('ORBIT_E2E_TOPOLOGY_PROVIDER=docker');

    $dockerCommands = [];
    Process::fake(function ($process) use (&$dockerCommands): ProcessResult {
        $dockerCommands[] = (string) $process->command;

        return Process::result();
    });
    Process::preventStrayProcesses();

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdev,
        operator: new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-operator', 'orbit-e2e-run'),
        gateway: e2ePestFakeInstance($commands, 'gateway'),
        dev: e2ePestFakeInstance($commands, 'dev'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
    ));
    $harness->setCheckouts([
        'operator' => '/home/orbit/orbit',
        'gateway' => '/home/orbit/orbit',
    ]);

    try {
        e2eConfigureCurrentCheckoutGatewaySettings($harness, 'operator');

        expect($dockerCommands)->toHaveCount(2)
            ->and($commands)->not->toContain("ssh:orbit:cat '/home/orbit/orbit/storage/app/orbit/ca/root.crt'")
            ->and($dockerCommands[0])->toContain("docker exec --user 'orbit' 'orbit-e2e-run-operator' sh -lc")
            ->and($dockerCommands[0])->toContain('/home/orbit/orbit/apps/cli')
            ->and($dockerCommands[0])->toContain('tmp="$(mktemp)"')
            ->and($dockerCommands[0])->toContain('sudo install -m 0664')
            ->and($dockerCommands[0])->toContain('ORBIT_GATEWAY_URL')
            ->and($dockerCommands[0])->toContain('ORBIT_GATEWAY_IDENTITY')
            ->and($dockerCommands[0])->toContain('http://gateway')
            ->and($dockerCommands[0])->not->toContain('.env.tmp')
            ->and($dockerCommands[0])->not->toContain('php apps/gateway/artisan tinker --execute')
            ->and($dockerCommands[0])->not->toContain('LocalGatewaySettings::current()')
            ->and($dockerCommands[1])->toContain("docker exec --user 'orbit' 'orbit-e2e-run-operator' sh -lc")
            ->and($dockerCommands[1])->toContain('/home/orbit/orbit')
            ->and($dockerCommands[1])->toContain('php apps/gateway/artisan tinker --execute')
            ->and($dockerCommands[1])->toContain('LocalGatewaySettings::current()')
            ->and($dockerCommands[1])->toContain('https://gateway')
            ->and($dockerCommands[1])->toContain('http://gateway/api/ca/root')
            ->and($dockerCommands[1])->toContain('ca_sha256')
            ->and($dockerCommands[1])->toContain('ca_pem_path');
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_TOPOLOGY_PROVIDER')
            : putenv("ORBIT_E2E_TOPOLOGY_PROVIDER={$previous}");
    }
});

it('seeds docker gateway current-checkout gateway settings through the cli env and root settings', function (): void {
    $previous = getenv('ORBIT_E2E_TOPOLOGY_PROVIDER');
    putenv('ORBIT_E2E_TOPOLOGY_PROVIDER=docker');

    $dockerCommands = [];
    Process::fake(function ($process) use (&$dockerCommands): ProcessResult {
        $dockerCommands[] = (string) $process->command;

        return Process::result();
    });
    Process::preventStrayProcesses();

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGateway,
        operator: e2ePestFakeInstance($commands, 'operator'),
        gateway: new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-gateway', 'orbit-e2e-run'),
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
    ));
    $harness->setCheckouts(['gateway' => '/home/orbit/orbit']);

    try {
        e2eConfigureCurrentCheckoutGatewaySettingsIfAvailable($harness);

        expect($dockerCommands)->toHaveCount(2)
            ->and($dockerCommands[0])->toContain("docker exec --user 'orbit' 'orbit-e2e-run-gateway' sh -lc")
            ->and($dockerCommands[0])->toContain('/home/orbit/orbit/apps/cli')
            ->and($dockerCommands[0])->toContain('tmp="$(mktemp)"')
            ->and($dockerCommands[0])->toContain('sudo install -m 0664')
            ->and($dockerCommands[0])->toContain('ORBIT_GATEWAY_URL')
            ->and($dockerCommands[0])->toContain('ORBIT_GATEWAY_IDENTITY')
            ->and($dockerCommands[0])->toContain('http://gateway')
            ->and($dockerCommands[0])->not->toContain('.env.tmp')
            ->and($dockerCommands[0])->not->toContain('php apps/gateway/artisan tinker --execute')
            ->and($dockerCommands[1])->toContain("docker exec --user 'orbit' 'orbit-e2e-run-gateway' sh -lc")
            ->and($dockerCommands[1])->toContain('/home/orbit/orbit')
            ->and($dockerCommands[1])->toContain('php apps/gateway/artisan tinker --execute')
            ->and($dockerCommands[1])->toContain('LocalGatewaySettings::current()')
            ->and($dockerCommands[1])->toContain('https://gateway')
            ->and($dockerCommands[1])->toContain('http://gateway/api/ca/root')
            ->and($commands)->toBe([]);
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_TOPOLOGY_PROVIDER')
            : putenv("ORBIT_E2E_TOPOLOGY_PROVIDER={$previous}");
    }
});

/**
 * @param  array<int, string>  $commands
 */
function e2ePestFakeInstance(array &$commands, string $name): E2EInstance
{
    return new class($commands, $name) implements E2EInstance
    {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(
            private array &$commands,
            private readonly string $name,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = "exec:{$this->name}:{$command}";

            return e2ePestProcessResult();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = "ssh:{$user}:{$command}";

            return e2ePestProcessResult();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.201.0.10';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };
}

/**
 * @param  array<int, string>  $commands
 */
function e2ePestFakeInstanceWithIp(array &$commands, string $name, string $ip): E2EInstance
{
    return new class($commands, $name, $ip) implements E2EInstance
    {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(
            private array &$commands,
            private readonly string $name,
            private readonly string $ip,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = "exec:{$this->name}:{$command}";

            return e2ePestProcessResult();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = "ssh:{$user}:{$command}";

            return e2ePestProcessResult();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return $this->ip;
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };
}

function e2ePestDeletableFakeInstance(int &$deleted, string $name): E2EInstance
{
    return new class($deleted, $name) implements E2EInstance
    {
        private int $deleted;

        public function __construct(int &$deleted, private readonly string $name)
        {
            $this->deleted = &$deleted;
        }

        public function name(): string
        {
            return $this->name;
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return e2ePestProcessResult();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return e2ePestProcessResult();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.201.0.10';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void
        {
            $this->deleted++;
        }
    };
}

function e2ePestHarnessTimer(E2ETopologyHarness $harness): mixed
{
    $property = new ReflectionProperty($harness, 'timer');

    return $property->getValue($harness);
}

function e2ePestProcessResult(): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('output')->andReturn('');
    $result->shouldReceive('errorOutput')->andReturn('');

    return $result;
}
