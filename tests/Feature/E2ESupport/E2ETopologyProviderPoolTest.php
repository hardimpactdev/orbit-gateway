<?php

declare(strict_types=1);

use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2ETopologyAcquisitionOptions;
use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\E2ETopologyProvider;
use App\E2E\Support\E2ETopologyProviderPool;
use App\E2E\Support\ProviderAvailability;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('selects the first topology provider with the requested kind available', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', false),
        fakeTopologyProvider('incus', true),
    ]);

    $selection = $pool->select(E2ETopologyKind::OperatorGateway);

    expect($selection->available())->toBeTrue()
        ->and($selection->provider()->name())->toBe('incus')
        ->and($selection->message)->toBe('incus: ready');
});

it('reports topology provider failures when none are available', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', false),
        fakeTopologyProvider('incus', false),
    ]);

    $selection = $pool->select(E2ETopologyKind::Operator);

    expect($selection->available())->toBeFalse()
        ->and($selection->message)->toContain('docker: unavailable')
        ->and($selection->message)->toContain('incus: unavailable');
});

it('skips topology providers that lack required capabilities', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', true, E2ETopologyCapabilities::containerFeature()),
        fakeTopologyProvider('incus', true, E2ETopologyCapabilities::vm()),
    ]);

    $required = new E2ETopologyCapabilities(
        realSsh: true,
        systemd: false,
        hostMutation: false,
        kernelNetworking: false,
    );

    $selection = $pool->select(E2ETopologyKind::Operator, $required);

    expect($selection->available())->toBeTrue()
        ->and($selection->provider()->name())->toBe('incus')
        ->and($selection->message)->toContain('incus:');
});

it('reports capability mismatch when no provider satisfies the requirement', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', true, E2ETopologyCapabilities::containerFeature()),
    ]);

    $required = new E2ETopologyCapabilities(
        realSsh: true,
        systemd: true,
        hostMutation: true,
        kernelNetworking: true,
    );

    $selection = $pool->select(E2ETopologyKind::Operator, $required);

    expect($selection->available())->toBeFalse()
        ->and($selection->message)->toContain('docker: capabilities do not satisfy required');
});

it('treats every requested capability flag independently', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', true, E2ETopologyCapabilities::containerFeature()),
        fakeTopologyProvider('incus', true, E2ETopologyCapabilities::vm()),
    ]);

    $required = new E2ETopologyCapabilities(
        realSsh: false,
        systemd: true,
        hostMutation: false,
        kernelNetworking: false,
    );

    $selection = $pool->select(E2ETopologyKind::Operator, $required);

    expect($selection->available())->toBeTrue()
        ->and($selection->provider()->name())->toBe('incus');
});

it('can select providers with Docker sibling container support', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', true, E2ETopologyCapabilities::containerFeature()),
    ]);

    $required = new E2ETopologyCapabilities(
        realSsh: false,
        systemd: false,
        hostMutation: false,
        kernelNetworking: false,
        dockerSiblingContainers: true,
    );

    $selection = $pool->select(E2ETopologyKind::Operator, $required);

    expect($selection->available())->toBeTrue()
        ->and($selection->provider()->name())->toBe('docker');
});

it('can create a docker topology provider from environment config', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(exitCode: 1),
    ]);

    withE2EProviderEnvironment([
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'docker',
    ], function (): void {
        $pool = E2ETopologyProviderPool::fromEnvironment();
        $selection = $pool->select(E2ETopologyKind::Operator);

        expect($selection->message)->toContain('docker:');
    });
});

function fakeTopologyProvider(string $name, bool $available, ?E2ETopologyCapabilities $capabilities = null): E2ETopologyProvider
{
    return new class($name, $available, $capabilities ?? E2ETopologyCapabilities::vm()) implements E2ETopologyProvider
    {
        public function __construct(
            private readonly string $name,
            private readonly bool $available,
            private readonly E2ETopologyCapabilities $capabilities,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function capabilities(): E2ETopologyCapabilities
        {
            return $this->capabilities;
        }

        public function availability(E2ETopologyKind $kind): ProviderAvailability
        {
            return $this->available
                ? ProviderAvailability::available('ready')
                : ProviderAvailability::unavailable('unavailable');
        }

        public function acquire(E2ETopologyKind $kind, string $runId, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options): E2ETopologyLease
        {
            throw new RuntimeException('Fake topology provider cannot acquire leases.');
        }
    };
}
