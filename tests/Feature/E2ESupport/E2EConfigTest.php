<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;

it('defaults topology cpus to 1 and topology memory to 2GiB', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->topologyCpus)->toBe('1')
            ->and($config->topologyMemory)->toBe('2GiB');
    });
});

it('keeps provisioning cpu/memory defaults at 2 / 2GiB', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->cpus)->toBe('2')
            ->and($config->memory)->toBe('2GiB');
    });
});

it('defaults provisioning images to Ubuntu 26.04 base image', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->sourceImage)->toBe('images:ubuntu/26.04')
            ->and($config->baseImage)->toBe('orbit-base-ubuntu-26.04-runtime');
    });
});

it('overrides topology limits independently from provisioning limits', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_CPUS' => '4',
        'ORBIT_E2E_MEMORY' => '8GiB',
        'ORBIT_E2E_TOPOLOGY_CPUS' => '2',
        'ORBIT_E2E_TOPOLOGY_MEMORY' => '3GiB',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->cpus)->toBe('4')
            ->and($config->memory)->toBe('8GiB')
            ->and($config->topologyCpus)->toBe('2')
            ->and($config->topologyMemory)->toBe('3GiB');
    });
});

it('preserves topology limits across forHost', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_TOPOLOGY_CPUS' => '1',
        'ORBIT_E2E_TOPOLOGY_MEMORY' => '2GiB',
    ], function (): void {
        $config = E2EConfig::fromEnvironment()->forHost('sidecar1');

        expect($config->host)->toBe('sidecar1')
            ->and($config->topologyCpus)->toBe('1')
            ->and($config->topologyMemory)->toBe('2GiB');
    });
});

it('defaults topology providers to incus independently from provisioning providers', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->topologyProviderNames)->toBe(['incus'])
            ->and($config->providerNames)->toBe(['incus'])
            ->and($config->forHost('sidecar1')->topologyProviderNames)->toBe(['incus']);
    });
});

it('expands topology provider auto to the safe vm-backed default', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'auto',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->topologyProviderNames)->toBe(['incus']);
    });
});

it('uses explicit topology providers without changing provisioning providers', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_PROVIDER' => 'incus',
        'ORBIT_E2E_TOPOLOGY_PROVIDERS' => 'docker, incus',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->providerNames)->toBe(['incus'])
            ->and($config->topologyProviderNames)->toBe(['docker', 'incus']);
    });
});

it('rejects removed hetzner provider configuration', function (string $key, string $value): void {
    withE2EConfigEnvironment([
        $key => $value,
    ], function (): void {
        expect(fn () => E2EConfig::fromEnvironment())
            ->toThrow(InvalidArgumentException::class, 'Unsupported E2E provider');
    });
})->with([
    'single hcloud provider' => ['ORBIT_E2E_PROVIDER', 'hcloud'],
    'single hetzner provider' => ['ORBIT_E2E_PROVIDER', 'hetzner'],
    'provider list containing hcloud' => ['ORBIT_E2E_PROVIDERS', 'incus,hcloud'],
    'topology provider containing hcloud' => ['ORBIT_E2E_TOPOLOGY_PROVIDERS', 'docker,hcloud'],
]);

it('parses docker test runners into hosts slots and container caps', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'beast:8:56, sidecar1:4:28, sidecar2:3:20',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->dockerHosts)->toBe(['beast', 'sidecar1', 'sidecar2'])
            ->and($config->dockerHostSlots)->toBe([
                'beast' => 8,
                'sidecar1' => 4,
                'sidecar2' => 3,
            ])
            ->and($config->dockerHostContainerCaps)->toBe([
                'beast' => 56,
                'sidecar1' => 28,
                'sidecar2' => 20,
            ])
            ->and($config->dockerMaxContainersForHost('beast'))->toBe(56)
            ->and($config->dockerMaxContainersForHost('sidecar1'))->toBe(28)
            ->and($config->dockerMaxContainersForHost('sidecar2'))->toBe(20)
            ->and($config->forHost('sidecar1')->dockerHosts)->toBe(['beast', 'sidecar1', 'sidecar2'])
            ->and($config->forHost('sidecar1')->dockerHostSlots)->toBe([
                'beast' => 8,
                'sidecar1' => 4,
                'sidecar2' => 3,
            ])
            ->and($config->forHost('sidecar1')->dockerHostContainerCaps)->toBe([
                'beast' => 56,
                'sidecar1' => 28,
                'sidecar2' => 20,
            ]);
    });
});

it('requires explicit docker container caps per host', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect(fn () => $config->dockerMaxContainersForHost('sidecar1'))
            ->toThrow(InvalidArgumentException::class, 'Missing Docker container cap for host [sidecar1]');
    });
});

it('rejects invalid docker test runner entries', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4',
    ], function (): void {
        expect(fn () => E2EConfig::fromEnvironment())
            ->toThrow(InvalidArgumentException::class, 'Invalid Docker test runner entry [sidecar1:4]. Expected host:slots:containers.');
    });
});

it('parses incus host specific vm caps', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_INCUS_HOSTS' => 'beast, sidecar1',
        'ORBIT_E2E_INCUS_HOST_VM_CAPS' => 'beast:12, sidecar1:6',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->incusHosts)->toBe(['beast', 'sidecar1'])
            ->and($config->incusHostCandidates())->toBe(['beast', 'sidecar1'])
            ->and($config->incusHostVmCaps)->toBe([
                'beast' => 12,
                'sidecar1' => 6,
            ])
            ->and($config->incusMaxVmsForHost('beast'))->toBe(12)
            ->and($config->incusMaxVmsForHost('sidecar1'))->toBe(6)
            ->and($config->forHost('beast')->incusHostVmCaps)->toBe([
                'beast' => 12,
                'sidecar1' => 6,
            ]);
    });
});

it('requires explicit incus vm caps per host', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect(fn () => $config->incusMaxVmsForHost('beast'))
            ->toThrow(InvalidArgumentException::class, 'Missing Incus VM cap for host [beast]');
    });
});

it('rejects invalid docker test runner slot and container counts', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:two:28',
    ], function (): void {
        expect(fn () => E2EConfig::fromEnvironment())
            ->toThrow(InvalidArgumentException::class, 'Invalid Docker test runner slot count [two] for host [sidecar1].');
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_TEST_RUNNERS' => 'sidecar1:4:many',
    ], function (): void {
        expect(fn () => E2EConfig::fromEnvironment())
            ->toThrow(InvalidArgumentException::class, 'Invalid Docker test runner container cap [many] for host [sidecar1].');
    });
});

it('parses docker image build hosts', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS' => 'beast, Sidecar1',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->dockerImageBuildHosts)->toBe(['beast', 'sidecar1'])
            ->and($config->forHost('sidecar1')->dockerImageBuildHosts)->toBe(['beast', 'sidecar1']);
    });
});

it('parses exclusive hosts for cross-backend lease protection', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_EXCLUSIVE_HOSTS' => 'beast, Sidecar1',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->exclusiveHosts)->toBe(['beast', 'sidecar1'])
            ->and($config->forHost('beast')->exclusiveHosts)->toBe(['beast', 'sidecar1']);
    });
});

it('parses incus host slots for the lease pool', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_INCUS_HOST_SLOTS' => 'sidecar1:1, sidecar2:2',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->incusHostSlots)->toBe([
            'sidecar1' => 1,
            'sidecar2' => 2,
        ])->and($config->forHost('sidecar1')->incusHostSlots)->toBe([
            'sidecar1' => 1,
            'sidecar2' => 2,
        ]);
    });
});

it('defaults e2e slot wait and stale seconds', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->slotWaitSeconds)->toBe(900)
            ->and($config->slotStaleSeconds)->toBe(7200)
            ->and($config->forHost('sidecar1')->slotWaitSeconds)->toBe(900)
            ->and($config->forHost('sidecar1')->slotStaleSeconds)->toBe(7200);
    });
});

it('reads e2e slot wait and stale seconds from the environment', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_SLOT_WAIT_SECONDS' => '30',
        'ORBIT_E2E_SLOT_STALE_SECONDS' => '120',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->slotWaitSeconds)->toBe(30)
            ->and($config->slotStaleSeconds)->toBe(120);
    });
});
