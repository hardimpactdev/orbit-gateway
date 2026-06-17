<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2EResourceLeasePool;
use App\E2E\Support\E2ETopologyAcquisitionOptions;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusHostPool;
use App\E2E\Support\IncusTopologyProvider;
use App\E2E\Support\IncusTopologyTemplate;
use App\E2E\Support\IncusWarmTopologyPool;
use App\E2E\Support\IncusWorkerNetwork;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Mockery as m;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

afterEach(function (): void {
    m::close();
});

function successfulProcessResult(): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('errorOutput')->andReturn('');
    $result->shouldReceive('output')->andReturn('');

    return $result;
}

function failedProcessResult(string $error = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(false);
    $result->shouldReceive('errorOutput')->andReturn($error);
    $result->shouldReceive('output')->andReturn('');

    return $result;
}

function mockIncusTopologyCurrentSnapshots(IncusHost $host, int $count): void
{
    // Snapshot sources for every role resolve through one batched host call.
    // Empty marker output makes each role fall back to its first candidate,
    // which is the base template/snapshot in the base artifact namespace.
    $host->shouldReceive('run')
        ->once()
        ->withArgs(fn (string $command, int $timeoutSeconds): bool => str_contains($command, '__orbit_snapshot_source')
            && str_contains($command, '/snapshots/clean-')
            && substr_count($command, 'else') >= $count)
        ->andReturn(successfulProcessResult());
}

function makeIncusTopologyTemplateTestConfig(string $topologyCpus = '1', string $topologyMemory = '2GiB', string $incusStoragePool = '', string $baseImage = '', string $topologyRootSize = '16GiB'): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: 'beast',
        sourceImage: '',
        baseImage: $baseImage,
        bootstrapUser: 'provisioner',
        operatorUser: 'operator',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: $topologyCpus,
        topologyMemory: $topologyMemory,
        topologyRootSize: $topologyRootSize,
        topologyStateSize: '4GiB',
        incusStoragePool: $incusStoragePool,
        dockerHosts: ['local'],
        keep: false,
        incusHostVmCaps: ['beast' => 4, 'sidecar1' => 4, 'sidecar2' => 4],
    );
}

it('maps each topology kind to expected roles', function (): void {
    $ingressKind = E2ETopologyKind::tryFromInput('operator_gateway_app-prod_ingress');

    expect($ingressKind)->not->toBeNull();

    expect(IncusTopologyTemplate::rolesFor(E2ETopologyKind::Operator))->toBe(['operator'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGateway))->toBe(['operator', 'gateway'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdev))->toBe(['operator', 'gateway', 'dev'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprod))->toBe(['operator', 'gateway', 'dev', 'prod'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAgent))->toBe(['operator', 'gateway', 'agent'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent))->toBe(['operator', 'gateway', 'dev', 'prod', 'agent'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress))->toBe(['operator', 'gateway', 'dev', 'prod', 'ingress'])
        ->and(IncusTopologyTemplate::rolesFor($ingressKind))->toBe(['operator', 'gateway', 'prod'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdevWebsocket))->toBe(['operator', 'gateway', 'dev'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket))->toBe(['operator', 'gateway', 'dev', 'prod'])
        ->and(IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket))->toBe(['operator', 'gateway', 'dev', 'prod', 'agent']);
});

it('generates correct template and clone names', function (): void {
    expect(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGateway, 'gateway'))
        ->toBe('orbit-template-gateway-base')
        ->and(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGatewayAppprodIngress, 'operator'))
        ->toBe('orbit-template-operator-base')
        ->and(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGatewayAppprodIngress, 'gateway'))
        ->toBe('orbit-template-gateway-base')
        ->and(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGatewayAppprodIngress, 'prod'))
        ->toBe('orbit-template-app-prod-base')
        ->and(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress, 'ingress'))
        ->toBe('orbit-template-ingress-base')
        ->and(IncusTopologyTemplate::snapshotName(E2ETopologyKind::OperatorGateway))
        ->toBe('clean-operator_gateway-base')
        ->and(IncusTopologyTemplate::snapshotName(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress))
        ->toBe('clean-operator_gateway_app-dev_app-prod_ingress-base')
        ->and(IncusTopologyTemplate::snapshotName(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket))
        ->toBe('clean-operator_gateway_app-dev_app-prod_agent_websocket-base')
        ->and(IncusTopologyTemplate::cloneName('abc123', 'operator'))
        ->toBe('orbit-e2e-abc123-operator');
});

it('generates Incus worker network names that fit the Linux interface limit', function (): void {
    $config = new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: 'beast',
        sourceImage: '',
        baseImage: '',
        bootstrapUser: 'provisioner',
        operatorUser: 'operator',
        instancePrefix: 'orbit-e2e-prepared',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyRootSize: '16GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: '',
        dockerHosts: ['local'],
        keep: false,
    );

    $network = IncusWorkerNetwork::forSlot($config, 200);

    expect($network->name)
        ->toBe('orbit-e2e-n-200')
        ->and(strlen($network->name))->toBeLessThanOrEqual(15);
});

it('opens worker network forwarding ahead of host firewall drops without enabling dnsmasq dns', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();
    $host = m::mock(IncusHost::class, [$config])->makePartial();

    $host->shouldReceive('run')
        ->once()
        ->withArgs(function (string $command, int $timeoutSeconds): bool {
            return $timeoutSeconds === 120
                && str_contains($command, 'raw.dnsmasq port=0')
                && ! str_contains($command, 'dhcp-option=option:dns-server')
                && str_contains($command, "while \$sudo_prefix iptables -D FORWARD -i 'orbit-e2e-n-1' -j ACCEPT")
                && str_contains($command, "iptables -I FORWARD 1 -i 'orbit-e2e-n-1' -j ACCEPT")
                && str_contains($command, "while \$sudo_prefix iptables -D FORWARD -o 'orbit-e2e-n-1' -j ACCEPT")
                && str_contains($command, "iptables -I FORWARD 1 -o 'orbit-e2e-n-1' -j ACCEPT");
        })
        ->andReturn(successfulProcessResult());

    IncusWorkerNetwork::forSlot($config, 1)->ensureOn($host);
});

it('returns true when all template instances and clean snapshots exist', function (): void {
    $host = m::mock(IncusHost::class);
    $host->shouldReceive('run')
        ->once()
        ->withArgs(function (string $command, int $timeoutSeconds): bool {
            return $timeoutSeconds === 30
                && str_contains($command, 'orbit-template-operator-base')
                && str_contains($command, 'orbit-template-gateway-base')
                && str_contains($command, 'orbit-template-app-dev-base')
                && str_contains($command, '/1.0/instances/orbit-template-operator-base/snapshots/clean-operator_gateway_app-dev_app-prod_agent_websocket-base')
                && ! str_contains($command, 'grep -q')
                && substr_count($command, '/snapshots/clean-operator_gateway_app-dev_app-prod_agent_websocket-base') === 3;
        })
        ->andReturn(successfulProcessResult());

    expect(IncusTopologyTemplate::availableOn($host, E2ETopologyKind::OperatorGatewayAppdev))->toBeTrue();
});

it('checks prepared snapshots by exact snapshot path instead of prefix matching', function (): void {
    $host = m::mock(IncusHost::class);
    $host->shouldReceive('run')
        ->once()
        ->withArgs(function (string $command): bool {
            return str_contains($command, "incus query '/1.0/instances/orbit-template-operator-base/snapshots/clean-operator_gateway_app-dev_app-prod_agent_websocket-base' >/dev/null 2>&1")
                && str_contains($command, "incus query '/1.0/instances/orbit-template-gateway-base/snapshots/clean-operator_gateway_app-dev_app-prod_agent_websocket-base' >/dev/null 2>&1")
                && ! str_contains($command, 'incus info \'orbit-template-operator-base\' --show-log=false')
                && ! str_contains($command, 'grep -q');
        })
        ->andReturn(failedProcessResult());

    expect(IncusTopologyTemplate::availableOn($host, E2ETopologyKind::OperatorGateway))->toBeFalse();
});

it('uses artifact-set suffixes for branch-specific Incus templates and snapshots', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Branch A/B',
    ], function (): void {
        expect(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGateway, 'gateway'))
            ->toBe('orbit-template-gateway-branch-a-b')
            ->and(IncusTopologyTemplate::snapshotName(E2ETopologyKind::OperatorGateway))
            ->toBe('clean-operator_gateway-branch-a-b');
    });
});

it('returns false when any template instance is missing', function (): void {
    $host = m::mock(IncusHost::class);
    $host->shouldReceive('run')
        ->once()
        ->andReturn(failedProcessResult());

    expect(IncusTopologyTemplate::availableOn($host, E2ETopologyKind::OperatorGateway))->toBeFalse();
});

it('parses ORBIT_E2E_INCUS_HOSTS correctly', function (): void {
    $previous = getenv('ORBIT_E2E_INCUS_HOSTS');
    putenv('ORBIT_E2E_INCUS_HOSTS=host1,host2,host3');

    try {
        $config = E2EConfig::fromEnvironment();
        $pool = IncusHostPool::fromEnvironment($config);

        $hosts = (new ReflectionClass($pool))->getProperty('hosts')->getValue($pool);

        expect($hosts)->toHaveCount(3)
            ->and($hosts[0]->config->host)->toBe('host1')
            ->and($hosts[1]->config->host)->toBe('host2')
            ->and($hosts[2]->config->host)->toBe('host3');
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_INCUS_HOSTS');
        } else {
            putenv("ORBIT_E2E_INCUS_HOSTS={$previous}");
        }
    }
});

it('returns single host when ORBIT_E2E_INCUS_HOSTS is unset', function (): void {
    $previous = getenv('ORBIT_E2E_INCUS_HOSTS');
    putenv('ORBIT_E2E_INCUS_HOSTS');

    try {
        $config = E2EConfig::fromEnvironment();
        $pool = IncusHostPool::fromEnvironment($config);

        $hosts = (new ReflectionClass($pool))->getProperty('hosts')->getValue($pool);

        expect($hosts)->toHaveCount(1)
            ->and($hosts[0]->config->host)->toBe($config->host);
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_INCUS_HOSTS');
        } else {
            putenv("ORBIT_E2E_INCUS_HOSTS={$previous}");
        }
    }
});

it('uses configured incus host slots as pool candidates when explicit hosts are unset', function (): void {
    withE2EEnvironment([
        'ORBIT_E2E_INCUS_HOSTS',
    ], [
        'ORBIT_E2E_INCUS_HOST_SLOTS' => 'sidecar1:1,sidecar2:2',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();
        $pool = IncusHostPool::fromEnvironment($config);

        $hosts = (new ReflectionClass($pool))->getProperty('hosts')->getValue($pool);

        expect($hosts)->toHaveCount(2)
            ->and($hosts[0]->config->host)->toBe('sidecar1')
            ->and($hosts[1]->config->host)->toBe('sidecar2');
    });
});

it('returns first host with required templates and capacity', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $hostWithout = m::mock(IncusHost::class, [$config])->makePartial();
    $hostWithout->shouldReceive('run')->andReturn(failedProcessResult());

    $hostWith = m::mock(IncusHost::class, [$config])->makePartial();
    $hostWith->shouldReceive('run')->andReturn(successfulProcessResult());
    $hostWith->shouldReceive('runningE2EInstanceCount')->andReturn(0);

    $pool = new IncusHostPool([$hostWithout, $hostWith]);

    expect($pool->firstAvailableFor(E2ETopologyKind::Operator))->toBe($hostWith);
});

it('returns null when no host has required templates', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $host1 = m::mock(IncusHost::class, [$config])->makePartial();
    $host1->shouldReceive('run')->andReturn(failedProcessResult());

    $host2 = m::mock(IncusHost::class, [$config])->makePartial();
    $host2->shouldReceive('run')->andReturn(failedProcessResult());

    $pool = new IncusHostPool([$host1, $host2]);

    expect($pool->firstAvailableFor(E2ETopologyKind::OperatorGateway))->toBeNull();
});

it('skips host when capacity is insufficient and selects the next', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $tightHost = m::mock(IncusHost::class, [$config])->makePartial();
    $tightHost->shouldReceive('run')->andReturn(successfulProcessResult());
    // 4 max - 3 running = 1 free slot, but we need 4 slots for OperatorGatewayAppdevAppprod.
    $tightHost->shouldReceive('runningE2EInstanceCount')->andReturn(3);

    $freeHost = m::mock(IncusHost::class, [$config])->makePartial();
    $freeHost->shouldReceive('run')->andReturn(successfulProcessResult());
    $freeHost->shouldReceive('runningE2EInstanceCount')->andReturn(0);

    $pool = new IncusHostPool([$tightHost, $freeHost]);

    expect($pool->firstAvailableFor(E2ETopologyKind::OperatorGatewayAppdevAppprod))->toBe($freeHost);
});

it('skips transient capacity checks when requested', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('run')->andReturn(successfulProcessResult());
    $host->shouldNotReceive('runningE2EInstanceCount');

    $availability = (new IncusHostPool([$host]))->availabilityFor(
        E2ETopologyKind::OperatorGatewayAppdevAppprod,
        checkCapacity: false,
    );

    expect($availability['host'])->toBe($host)
        ->and($availability['reason'])->toBeNull();
});

it('does not fail provider availability on transient incus capacity', function (): void {
    $probedCapacity = false;

    Process::fake(function ($process) use (&$probedCapacity) {
        if (str_contains($process->command, 'incus list --format json')) {
            $probedCapacity = true;
        }

        return Process::result();
    });

    withE2EEnvironment([
        'ORBIT_E2E_INCUS_HOSTS',
    ], [
        'ORBIT_E2E_INCUS_HOSTS' => 'sidecar1',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'sidecar1:2',
    ], function () use (&$probedCapacity): void {
        $provider = new IncusTopologyProvider(E2EConfig::fromEnvironment());
        $availability = $provider->availability(E2ETopologyKind::OperatorGateway);

        expect($availability->available)->toBeTrue()
            ->and($availability->message)->toContain('sidecar1')
            ->and($probedCapacity)->toBeFalse();
    });
});

it('reports warm prepared topology availability only when warm stateful snapshots exist', function (): void {
    Process::fake(function ($process) {
        expect($process->command)
            ->toContain('orbit-e2e-warm-')
            ->toContain('/snapshots/warm-ready')
            ->not->toContain('clean-operator_gateway_app-dev_app-prod_agent-base');

        return Process::result();
    });

    withE2EEnvironment([
        'ORBIT_E2E_INCUS_HOSTS',
    ], [
        'ORBIT_E2E_INCUS_WARM_SNAPSHOTS' => '1',
        'ORBIT_E2E_INCUS_HOSTS' => 'sidecar1',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'sidecar1:4',
        'ORBIT_E2E_INCUS_WARM_SNAPSHOT_SLOTS' => '2',
    ], function (): void {
        $provider = new IncusTopologyProvider(E2EConfig::fromEnvironment());
        $availability = $provider->availability(E2ETopologyKind::OperatorGateway);

        expect($availability->available)->toBeTrue()
            ->and($availability->message)->toContain('warm prepared topology operator_gateway is available on sidecar1');
    });
});

it('fails warm prepared topology availability when warm snapshots are missing', function (): void {
    Process::fake(fn () => Process::result(exitCode: 1));

    withE2EEnvironment([
        'ORBIT_E2E_INCUS_HOSTS',
    ], [
        'ORBIT_E2E_INCUS_WARM_SNAPSHOTS' => '1',
        'ORBIT_E2E_INCUS_HOSTS' => 'sidecar1',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'sidecar1:4',
    ], function (): void {
        $provider = new IncusTopologyProvider(E2EConfig::fromEnvironment());
        $availability = $provider->availability(E2ETopologyKind::OperatorGateway);

        expect($availability->available)->toBeFalse()
            ->and($availability->message)->toContain('Run composer e2e:prepare-warm-topology -- --force operator_gateway');
    });
});

it('bypasses warm snapshot acquisition for source-mounted retained Incus requests', function (): void {
    withE2EEnvironment([
        'ORBIT_E2E_INCUS_HOSTS',
    ], [
        'ORBIT_E2E_INCUS_WARM_SNAPSHOTS' => '1',
        'ORBIT_E2E_INCUS_HOSTS' => 'sidecar1',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'sidecar1:4',
    ], function (): void {
        $provider = new IncusTopologyProvider(E2EConfig::fromEnvironment());
        $method = new ReflectionMethod($provider, 'shouldAcquireWarmSnapshots');
        $method->setAccessible(true);

        expect($method->invoke($provider, new E2ETopologyAcquisitionOptions(
            sourceMountedCheckout: true,
        )))->toBeFalse()
            ->and($method->invoke($provider, new E2ETopologyAcquisitionOptions))->toBeTrue();
    });
});

it('plans stable warm topology slot names per topology and artifact set', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Agent branch',
    ], function (): void {
        $runId = IncusWarmTopologyPool::runId(E2ETopologyKind::OperatorGatewayAgent, 2);

        expect($runId)->toStartWith('warm-')
            ->toEndWith('-s2')
            ->and(IncusWarmTopologyPool::instanceName(E2ETopologyKind::OperatorGatewayAgent, 2, 'operator'))
            ->toBe("orbit-e2e-{$runId}-operator")
            ->and(IncusWarmTopologyPool::instanceNames(E2ETopologyKind::OperatorGatewayAgent, 2))
            ->toBe([
                "orbit-e2e-{$runId}-operator",
                "orbit-e2e-{$runId}-gateway",
                "orbit-e2e-{$runId}-agent",
            ]);
    });
});

it('leases weighted incus vm capacity before acquiring a topology', function (): void {
    $leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));

    exec('rm -rf '.escapeshellarg($leaseDirectory));

    $pool = new E2EResourceLeasePool($leaseDirectory, waitSeconds: 0, staleSeconds: 60);
    $heldLease = $pool->acquireWeighted('incus', ['sidecar1' => 1], slots: 1);

    Process::fake(fn () => Process::result());

    try {
        withE2EEnvironment([
            'ORBIT_E2E_INCUS_HOSTS',
        ], [
            'ORBIT_E2E_INCUS_HOSTS' => 'sidecar1',
            'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'sidecar1:1',
            'ORBIT_E2E_LEASE_DIRECTORY' => $leaseDirectory,
            'ORBIT_E2E_SLOT_WAIT_SECONDS' => '0',
        ], function (): void {
            $provider = new IncusTopologyProvider(E2EConfig::fromEnvironment());

            expect(fn () => $provider->acquire(
                E2ETopologyKind::Operator,
                'run123',
                new E2EPhaseTimer,
                new E2ETopologyAcquisitionOptions,
            ))->toThrow(RuntimeException::class, 'No incus E2E capacity for 1 slots became available');
        });
    } finally {
        $heldLease->release();
        exec('rm -rf '.escapeshellarg($leaseDirectory));
    }
});

it('can restrict availability checks to a leased incus host', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $host1 = m::mock(IncusHost::class, [$config->forHost('sidecar1')])->makePartial();
    $host1->shouldNotReceive('run');

    $host2 = m::mock(IncusHost::class, [$config->forHost('sidecar2')])->makePartial();
    $host2->shouldReceive('run')->andReturn(successfulProcessResult());
    $host2->shouldReceive('runningE2EInstanceCount')->andReturn(0);

    $availability = (new IncusHostPool([$host1, $host2]))->availabilityFor(
        E2ETopologyKind::OperatorGateway,
        hostNames: ['sidecar2'],
    );

    expect($availability['host'])->toBe($host2)
        ->and($availability['reason'])->toBeNull();
});

it('returns null when every host with templates is at capacity', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $host1 = m::mock(IncusHost::class, [$config])->makePartial();
    $host1->shouldReceive('run')->andReturn(successfulProcessResult());
    $host1->shouldReceive('runningE2EInstanceCount')->andReturn(4);

    $host2 = m::mock(IncusHost::class, [$config])->makePartial();
    $host2->shouldReceive('run')->andReturn(successfulProcessResult());
    $host2->shouldReceive('runningE2EInstanceCount')->andReturn(4);

    $pool = new IncusHostPool([$host1, $host2]);

    expect($pool->firstAvailableFor(E2ETopologyKind::Operator))->toBeNull();
});

it('reports capacity details when every prepared Incus host is full', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('run')->andReturn(successfulProcessResult());
    $host->shouldReceive('runningE2EInstanceCount')->andReturn(4);

    $availability = (new IncusHostPool([$host]))->availabilityFor(E2ETopologyKind::OperatorGateway);

    expect($availability['host'])->toBeNull()
        ->and($availability['reason'])->toBe('beast has 0/2 free VM slots (4/4 Orbit E2E VMs running)');
});

it('builds a batch script that copies all roles in parallel, applies limits, then starts in parallel', function (): void {
    $config = makeIncusTopologyTemplateTestConfig('1', '2GiB');
    $host = m::mock(IncusHost::class, [$config])->makePartial();
    mockIncusTopologyCurrentSnapshots($host, 4);

    $script = IncusTopologyTemplate::buildBatchScript(
        $host,
        E2ETopologyKind::OperatorGatewayAppdevAppprod,
        'runX',
        IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAppdevAppprod),
    );

    // Every role gets a backgrounded copy with a captured pid.
    foreach (['operator', 'gateway', 'dev', 'prod'] as $role) {
        $artifactRole = match ($role) {
            'operator' => 'operator',
            'dev' => 'app-dev',
            'prod' => 'app-prod',
            default => $role,
        };

        expect($script)->toContain("incus copy 'orbit-template-{$artifactRole}-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base' 'orbit-e2e-runX-{$role}' &");
        expect($script)->toContain("incus start 'orbit-e2e-runX-{$role}' &");
        expect($script)->toContain("incus config set 'orbit-e2e-runX-{$role}' limits.cpu='1' limits.memory='2GiB'");
        expect($script)->toContain("incus config device override 'orbit-e2e-runX-{$role}' eth0 hwaddr=");
        expect($script)->toContain("incus config device set 'orbit-e2e-runX-{$role}' root size='16GiB' || incus config device override 'orbit-e2e-runX-{$role}' root size='16GiB'");
    }

    // All copy commands appear before any start command (the dev block is
    // copy/wait/limits/start/wait, in that order).
    $firstStartPos = strpos($script, 'incus start');
    $firstIdentityPos = strpos($script, 'incus config device override');
    foreach (['operator', 'gateway', 'dev', 'prod'] as $role) {
        $artifactRole = match ($role) {
            'operator' => 'operator',
            'dev' => 'app-dev',
            'prod' => 'app-prod',
            default => $role,
        };
        $copyPos = strpos($script, "incus copy 'orbit-template-{$artifactRole}-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base'");
        expect($copyPos)->toBeLessThan($firstStartPos);
    }

    expect($firstIdentityPos)->toBeLessThan($firstStartPos);
    expect(strpos($script, "root size='16GiB'"))->toBeLessThan($firstStartPos);
});

it('attaches cloned Incus topology instances to the worker network before start', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();
    $host = m::mock(IncusHost::class, [$config])->makePartial();
    mockIncusTopologyCurrentSnapshots($host, 2);
    $network = IncusWorkerNetwork::forSlot($config, 3);

    $script = IncusTopologyTemplate::buildBatchScript(
        $host,
        E2ETopologyKind::OperatorGateway,
        'runNetwork',
        IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGateway),
        network: $network,
    );

    expect($script)
        ->toContain("incus config device set 'orbit-e2e-runNetwork-operator' eth0 network 'orbit-e2e-n-3'")
        ->toContain("incus config device add 'orbit-e2e-runNetwork-gateway' eth0 nic network='orbit-e2e-n-3' name=eth0")
        ->and(strpos($script, "eth0 network 'orbit-e2e-n-3'"))->toBeLessThan(strpos($script, "incus start 'orbit-e2e-runNetwork-operator'"));
});

it('falls back from branch-specific Incus snapshots to base snapshots per role', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Branch A/B',
    ], function (): void {
        $config = makeIncusTopologyTemplateTestConfig();
        $host = m::mock(IncusHost::class, [$config])->makePartial();
        $checks = [];

        $host->shouldReceive('run')
            ->andReturnUsing(function (string $command) use (&$checks): ProcessResult {
                if (str_contains($command, '__orbit_snapshot_source')) {
                    $checks[] = $command;

                    $result = m::mock(ProcessResult::class);
                    $result->shouldReceive('successful')->andReturn(true);
                    $result->shouldReceive('errorOutput')->andReturn('');
                    $result->shouldReceive('output')->andReturn(implode("\n", [
                        '__orbit_snapshot_source operator orbit-template-operator-branch-a-b clean-operator_gateway_app-dev_app-prod_agent_websocket-branch-a-b',
                        '__orbit_snapshot_source gateway orbit-template-gateway-branch-a-b clean-operator_gateway_app-dev_app-prod_agent_websocket-branch-a-b',
                        '__orbit_snapshot_source agent orbit-template-agent-base clean-operator_gateway_app-dev_app-prod_agent_websocket-base',
                    ]));

                    return $result;
                }

                return successfulProcessResult();
            });

        $script = IncusTopologyTemplate::buildBatchScript(
            $host,
            E2ETopologyKind::OperatorGatewayAgent,
            'runBranch',
            IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAgent),
        );
        $checkedSnapshots = implode("\n", $checks);

        expect($script)
            ->toContain("incus copy 'orbit-template-operator-branch-a-b/clean-operator_gateway_app-dev_app-prod_agent_websocket-branch-a-b'")
            ->toContain("incus copy 'orbit-template-gateway-branch-a-b/clean-operator_gateway_app-dev_app-prod_agent_websocket-branch-a-b'")
            ->toContain("incus copy 'orbit-template-agent-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base'")
            ->not->toContain('orbit-template-operator-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base')
            ->not->toContain('orbit-template-gateway-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base')
            ->and($checks)->toHaveCount(1)
            ->and($checkedSnapshots)
            ->toContain('orbit-template-agent-branch-a-b/snapshots/clean-operator_gateway_app-dev_app-prod_agent_websocket-branch-a-b')
            ->toContain('orbit-template-agent-base/snapshots/clean-operator_gateway_app-dev_app-prod_agent_websocket-base');
    });
});

it('adds an explicit storage pool to topology clone copies when configured', function (): void {
    $config = makeIncusTopologyTemplateTestConfig(incusStoragePool: 'orbit-e2e');
    $host = m::mock(IncusHost::class, [$config])->makePartial();
    mockIncusTopologyCurrentSnapshots($host, 2);

    $script = IncusTopologyTemplate::buildBatchScript(
        $host,
        E2ETopologyKind::OperatorGateway,
        'runZ',
        IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGateway),
    );

    expect($script)->toContain("incus copy 'orbit-template-operator-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base' 'orbit-e2e-runZ-operator' --storage 'orbit-e2e' &")
        ->and($script)->toContain("incus copy 'orbit-template-gateway-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base' 'orbit-e2e-runZ-gateway' --storage 'orbit-e2e' &");
});

it('adds a source mount before start for source-mounted Incus retained topologies', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();
    $host = m::mock(IncusHost::class, [$config])->makePartial();
    mockIncusTopologyCurrentSnapshots($host, 2);
    $host->shouldReceive('run')
        ->once()
        ->withArgs(fn (string $command, int $timeoutSeconds): bool => $timeoutSeconds === 30
            && $command === "test -d '/srv/orbit-source' && test -f '/srv/orbit-source/apps/cli/orbit'")
        ->andReturn(successfulProcessResult());

    withE2EConfigEnvironment([
        'ORBIT_E2E_INCUS_SOURCE_PATH' => '/srv/orbit-source',
    ], function () use ($host): void {
        $script = IncusTopologyTemplate::buildBatchScript(
            $host,
            E2ETopologyKind::OperatorGateway,
            'runSource',
            IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGateway),
            sourceMounted: true,
        );

        expect($script)
            ->toContain("if incus config device get 'orbit-e2e-runSource-operator' orbit-source path >/dev/null 2>&1; then")
            ->toContain("  incus config device add 'orbit-e2e-runSource-operator' orbit-source disk source='/srv/orbit-source' path='/home/orbit/orbit' shift=true")
            ->toContain("  incus config device add 'orbit-e2e-runSource-gateway' orbit-source disk source='/srv/orbit-source' path='/home/orbit/orbit' shift=true")
            ->toContain("incus config device set 'orbit-e2e-runSource-operator' orbit-source source='/srv/orbit-source'")
            ->toContain("incus config device set 'orbit-e2e-runSource-operator' orbit-source path='/home/orbit/orbit'")
            ->toContain("incus config device set 'orbit-e2e-runSource-operator' orbit-source shift=true")
            ->not->toContain('shift=true || true')
            ->and(strpos($script, "orbit-source disk source='/srv/orbit-source'"))->toBeLessThan(strpos($script, "incus start 'orbit-e2e-runSource-operator'"));
    });
});

it('does not add a source mount for ordinary prepared Incus acquisitions', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();
    $host = m::mock(IncusHost::class, [$config])->makePartial();
    mockIncusTopologyCurrentSnapshots($host, 2);

    $script = IncusTopologyTemplate::buildBatchScript(
        $host,
        E2ETopologyKind::OperatorGateway,
        'runPrepared',
        IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGateway),
    );

    expect($script)->not->toContain('orbit-source');
});

it('requires the requested Incus roles in the prepared full snapshot', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $config = makeIncusTopologyTemplateTestConfig(baseImage: 'orbit-base-ubuntu-26.04-runtime');
        $host = m::mock(IncusHost::class, [$config])->makePartial();
        $host->shouldReceive('run')
            ->once()
            ->withArgs(fn (string $command, int $timeoutSeconds): bool => $timeoutSeconds === 30
                && str_contains($command, "incus info 'orbit-template-operator-base'")
                && str_contains($command, "incus info 'orbit-template-gateway-base'")
                && str_contains($command, "incus info 'orbit-template-app-dev-base'")
                && str_contains($command, "incus info 'orbit-template-app-prod-base'")
                && str_contains($command, "incus info 'orbit-template-agent-base'")
                && str_contains($command, 'clean-operator_gateway_app-dev_app-prod_agent_websocket-base')
                && ! str_contains($command, "incus image info 'orbit-base-ubuntu-26.04-runtime'")
                && ! str_contains($command, "snapshots/clean-operator-base'")
                && ! str_contains($command, "snapshots/clean-operator_gateway-base'")
                && ! str_contains($command, 'orbit-template-ingress-base'))
            ->andReturn(successfulProcessResult());

        expect(IncusTopologyTemplate::availableOn($host, E2ETopologyKind::OperatorGatewayAppdevAppprodAgent))->toBeTrue();
    });
});

it('clones only requested Incus roles from the prepared full snapshot', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $config = makeIncusTopologyTemplateTestConfig();
        $host = m::mock(IncusHost::class, [$config])->makePartial();
        $snapshotChecks = [];

        $host->shouldReceive('run')
            ->andReturnUsing(function (string $command) use (&$snapshotChecks): ProcessResult {
                if (str_contains($command, '/snapshots/')) {
                    $snapshotChecks[] = $command;
                }

                return successfulProcessResult();
            });

        $script = IncusTopologyTemplate::buildBatchScript(
            $host,
            E2ETopologyKind::OperatorGatewayAgent,
            'runPrepared',
            IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGatewayAgent),
        );
        $checkedSnapshots = implode("\n", $snapshotChecks);

        expect($script)
            ->toContain("incus copy 'orbit-template-operator-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base'")
            ->toContain("incus copy 'orbit-template-gateway-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base'")
            ->toContain("incus copy 'orbit-template-agent-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base'")
            ->not->toContain('orbit-template-app-dev-base')
            ->not->toContain('orbit-template-app-prod-base')
            ->not->toContain("orbit-template-operator-base/clean-operator-base'")
            ->and($checkedSnapshots)
            ->toContain("snapshots/clean-operator_gateway_app-dev_app-prod_agent_websocket-base'")
            ->not->toContain("snapshots/clean-operator_gateway-base'")
            ->not->toContain("snapshots/clean-operator-base'");
    });
});

it('prepared Incus acquisition retargets selected snapshot roles without dynamic base provisioning', function (): void {
    $source = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));

    expect($source)
        ->toContain('prepareInstances($instances, $this->config, $sshKeyPair, $timer, $options, $kind)')
        ->toContain('retargetTopology($instances, $config, $sshKeyPair, $kind, $options->sourceMountedCheckout, $timer)')
        ->toContain('--public-host=%s --skip-gateway-service-install')
        ->toContain('$bootstrapArguments')
        ->toContain('runGatewayArtisan($gateway')
        ->toContain('E2ECommand::gatewayArtisan(')
        ->toContain("'cd /home/orbit/orbit && php apps/gateway/artisan '.\$arguments")
        ->toContain('/.config/orbit')
        ->toContain('config.json')
        ->toContain('orbit:internal:bake-app-node app-dev-1 --role=app-dev')
        ->toContain('seedAppdevDatabaseAndRedis($gateway')
        ->toContain('orbit:internal:bake-ingress-node app-prod-1')
        ->toContain('E2EPreparedTopology::prodHostsIngressRole($kind)')
        ->toContain('orbit:internal:bake-app-node app-prod-1 --role=app-prod')
        ->toContain('orbit:internal:bake-agent-node agent-1')
        ->toContain('orbit:internal:bake-websocket-node app-dev-1')
        ->toContain("private const string DevWireGuardIp = '10.6.0.4'")
        ->toContain("private const string ProdWireGuardIp = '10.6.0.5'")
        ->toContain("private const string AgentWireGuardIp = '10.6.0.6'")
        ->toContain('escapeshellarg(self::DevWireGuardIp)')
        ->toContain('escapeshellarg(self::ProdWireGuardIp)')
        ->toContain('escapeshellarg(self::AgentWireGuardIp)')
        ->toContain("foreach (['dev', 'prod', 'agent', 'ingress'] as \$role)")
        ->not->toContain('cd /home/orbit/orbit && php artisan')
        ->not->toContain('prepared.node-new')
        ->not->toContain('launchPreparedBaseRole')
        ->not->toContain('& PID_');
});

it('does not use synthetic provider-interface routes for prepared gateway clones', function (): void {
    $source = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));

    expect($source)->not->toContain('ip addr add')
        ->and($source)->not->toContain('ip route replace')
        ->and($source)->not->toContain('DockerTopologyNetworkPlan')
        ->and($source)->toContain("private const string GatewayWireGuardIp = '10.6.0.2'")
        ->and($source)->toContain("private const string DevWireGuardIp = '10.6.0.4'")
        ->and($source)->toContain("private const string AgentWireGuardIp = '10.6.0.6'")
        ->and($source)->toContain('retargetRealWireGuard')
        ->and($source)->toContain('orbit:internal:bake-agent-node agent-1')
        ->and($source)->toContain('E2EWgEasyGateway');
});

it('enables stateful migration before starting clones when stateful reset is requested', function (): void {
    $previous = getenv('ORBIT_E2E_TOPOLOGY_RESET');
    putenv('ORBIT_E2E_TOPOLOGY_RESET=stateful-restore');

    try {
        $config = makeIncusTopologyTemplateTestConfig();
        $host = m::mock(IncusHost::class, [$config])->makePartial();
        mockIncusTopologyCurrentSnapshots($host, 2);

        $script = IncusTopologyTemplate::buildBatchScript(
            $host,
            E2ETopologyKind::OperatorGateway,
            'runState',
            IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGateway),
        );

        expect($script)->toContain("incus config set 'orbit-e2e-runState-operator' migration.stateful=true")
            ->and($script)->toContain("incus config set 'orbit-e2e-runState-gateway' migration.stateful=true")
            ->and($script)->toContain("incus config device set 'orbit-e2e-runState-operator' root size.state='4GiB' || incus config device override 'orbit-e2e-runState-operator' root size.state='4GiB'")
            ->and($script)->toContain("incus config device set 'orbit-e2e-runState-gateway' root size.state='4GiB' || incus config device override 'orbit-e2e-runState-gateway' root size.state='4GiB'")
            ->and(strpos($script, 'migration.stateful=true'))->toBeLessThan(strpos($script, 'incus start'));
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_TOPOLOGY_RESET');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_RESET={$previous}");
        }
    }
});

it('can explicitly build stateful-ready topology clone scripts for warm snapshots', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();
    $host = m::mock(IncusHost::class, [$config])->makePartial();
    mockIncusTopologyCurrentSnapshots($host, 2);

    $script = IncusTopologyTemplate::buildBatchScript(
        $host,
        E2ETopologyKind::OperatorGateway,
        'runWarm',
        IncusTopologyTemplate::rolesFor(E2ETopologyKind::OperatorGateway),
        stateful: true,
    );

    expect($script)->toContain("incus config set 'orbit-e2e-runWarm-operator' migration.stateful=true")
        ->and($script)->toContain("incus config set 'orbit-e2e-runWarm-gateway' migration.stateful=true")
        ->and($script)->toContain("incus config device set 'orbit-e2e-runWarm-operator' root size.state='4GiB' || incus config device override 'orbit-e2e-runWarm-operator' root size.state='4GiB'");
});

it('clones runs the batch script through the host and waits for each agent', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();
    $host = m::mock(IncusHost::class, [$config])->makePartial();

    $captured = null;
    $host->shouldReceive('runWithoutMultiplexing')
        ->once()
        ->withArgs(function (string $command) use (&$captured): bool {
            $captured = $command;

            return str_contains($command, 'incus copy');
        })
        ->andReturn(successfulProcessResult());
    $host->shouldReceive('run')
        ->andReturn(successfulProcessResult());

    $instances = IncusTopologyTemplate::clone($host, E2ETopologyKind::OperatorGateway, 'runY');

    expect($instances)->toHaveKey('operator')
        ->toHaveKey('gateway')
        ->and($captured)->toContain('incus copy')
        ->and($captured)->toContain("'orbit-e2e-runY-operator'")
        ->and($captured)->toContain("'orbit-e2e-runY-gateway'")
        ->and($captured)->toContain("incus config device override 'orbit-e2e-runY-operator' eth0 hwaddr=");
});

it('throws when the batch script fails, surfacing the host error output', function (): void {
    $config = makeIncusTopologyTemplateTestConfig();

    $failure = m::mock(ProcessResult::class);
    $failure->shouldReceive('successful')->andReturn(false);
    $failure->shouldReceive('errorOutput')->andReturn("incus copy: not found\n");

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('runWithoutMultiplexing')->andReturn($failure);

    expect(fn () => IncusTopologyTemplate::clone($host, E2ETopologyKind::Operator, 'runZ'))
        ->toThrow(RuntimeException::class, 'Topology batch failed for operator');
});
