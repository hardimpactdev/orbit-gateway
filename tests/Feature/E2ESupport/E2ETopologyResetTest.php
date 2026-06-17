<?php

declare(strict_types=1);

use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\SshKeyPair;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

it('cleanup is idempotent', function (): void {
    $operator = m::mock(E2EInstance::class);
    $operator->shouldReceive('delete')->once();
    $teardownCalls = 0;

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: $operator,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: fn () => [],
        teardown: function () use (&$teardownCalls): void {
            $teardownCalls++;
        },
    );

    $lease->cleanup();
    $lease->cleanup();

    expect($teardownCalls)->toBe(1);
});

it('defaults the gateway api address to the standard wireguard address', function (): void {
    $operator = m::mock(E2EInstance::class);

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: $operator,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: fn () => [],
    );

    expect($lease->gatewayApiIp())->toBe('10.6.0.2');
});

it('reset calls cleanup and acquires fresh instances', function (): void {
    $oldOperator = m::mock(E2EInstance::class);
    $oldOperator->shouldReceive('delete')->once();

    $newOperator = m::mock(E2EInstance::class);

    $callCount = 0;
    $rebuild = function () use ($newOperator, &$callCount): array {
        $callCount++;

        return [
            'instances' => ['operator' => $newOperator],
            'snapshotReset' => null,
        ];
    };

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: $oldOperator,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: $rebuild,
    );

    $lease->reset();

    expect($callCount)->toBe(1);
});

it('returns new instances after reset', function (): void {
    $oldOperator = m::mock(E2EInstance::class);
    $oldGateway = m::mock(E2EInstance::class);
    $oldDev = m::mock(E2EInstance::class);
    $oldProd = m::mock(E2EInstance::class);

    $oldOperator->shouldReceive('delete')->once();
    $oldGateway->shouldReceive('delete')->once();
    $oldDev->shouldReceive('delete')->once();
    $oldProd->shouldReceive('delete')->once();

    $newOperator = m::mock(E2EInstance::class);
    $newGateway = m::mock(E2EInstance::class);
    $newDev = m::mock(E2EInstance::class);
    $newProd = m::mock(E2EInstance::class);

    $rebuild = fn (): array => [
        'instances' => [
            'operator' => $newOperator,
            'gateway' => $newGateway,
            'dev' => $newDev,
            'prod' => $newProd,
        ],
        'snapshotReset' => null,
    ];

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdevAppprod,
        operator: $oldOperator,
        gateway: $oldGateway,
        dev: $oldDev,
        prod: $oldProd,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: $rebuild,
    );

    $lease->reset();

    expect($lease->operator())->toBe($newOperator)
        ->and($lease->gateway())->toBe($newGateway)
        ->and($lease->devApp())->toBe($newDev)
        ->and($lease->prodApp())->toBe($newProd);
});

it('runs the snapshot reset closure when ORBIT_E2E_TOPOLOGY_RESET is snapshot-restore', function (): void {
    $previous = getenv('ORBIT_E2E_TOPOLOGY_RESET');
    putenv('ORBIT_E2E_TOPOLOGY_RESET=snapshot-restore');

    try {
        $operator = m::mock(E2EInstance::class);
        // No delete() call — snapshot-restore must reuse the same instance handles.
        $operator->shouldNotReceive('delete');

        $callCount = 0;
        $snapshotReset = function () use (&$callCount): void {
            $callCount++;
        };

        $lease = new E2ETopologyLease(
            kind: E2ETopologyKind::Operator,
            operator: $operator,
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
            rebuild: function (): array {
                throw new RuntimeException('rebuild should not run for snapshot-restore');
            },
            snapshotReset: $snapshotReset,
        );

        $lease->reset();

        expect($callCount)->toBe(1)
            ->and($lease->operator())->toBe($operator);
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_TOPOLOGY_RESET');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_RESET={$previous}");
        }
    }
});

it('runs the snapshot reset closure when ORBIT_E2E_TOPOLOGY_RESET is stateful-restore', function (): void {
    $previous = getenv('ORBIT_E2E_TOPOLOGY_RESET');
    putenv('ORBIT_E2E_TOPOLOGY_RESET=stateful-restore');

    try {
        $operator = m::mock(E2EInstance::class);
        $operator->shouldNotReceive('delete');

        $callCount = 0;
        $snapshotReset = function () use (&$callCount): void {
            $callCount++;
        };

        $lease = new E2ETopologyLease(
            kind: E2ETopologyKind::Operator,
            operator: $operator,
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
            rebuild: function (): array {
                throw new RuntimeException('rebuild should not run for stateful-restore');
            },
            snapshotReset: $snapshotReset,
        );

        $lease->reset();

        expect($callCount)->toBe(1)
            ->and($lease->operator())->toBe($operator);
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_TOPOLOGY_RESET');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_RESET={$previous}");
        }
    }
});

it('falls back to fresh-clone when snapshot-restore is requested without a snapshot reset closure', function (): void {
    $previous = getenv('ORBIT_E2E_TOPOLOGY_RESET');
    putenv('ORBIT_E2E_TOPOLOGY_RESET=snapshot-restore');

    try {
        $oldOperator = m::mock(E2EInstance::class);
        $oldOperator->shouldReceive('delete')->once();

        $newOperator = m::mock(E2EInstance::class);
        $teardownCalls = 0;

        $lease = new E2ETopologyLease(
            kind: E2ETopologyKind::Operator,
            operator: $oldOperator,
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
            rebuild: fn (): array => [
                'instances' => ['operator' => $newOperator],
                'snapshotReset' => null,
            ],
            teardown: function () use (&$teardownCalls): void {
                $teardownCalls++;
            },
        );

        $lease->reset();

        expect($lease->operator())->toBe($newOperator)
            ->and($teardownCalls)->toBe(0);
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_TOPOLOGY_RESET');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_RESET={$previous}");
        }
    }
});

it('defers teardown until final cleanup across fresh-clone resets', function (): void {
    $oldOperator = m::mock(E2EInstance::class);
    $oldOperator->shouldReceive('delete')->once();

    $newOperator = m::mock(E2EInstance::class);
    $newOperator->shouldReceive('delete')->once();

    $teardownCalls = 0;

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: $oldOperator,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: fn (): array => [
            'instances' => ['operator' => $newOperator],
            'snapshotReset' => null,
        ],
        teardown: function () use (&$teardownCalls): void {
            $teardownCalls++;
        },
    );

    $lease->reset();

    expect($teardownCalls)->toBe(0);

    $lease->cleanup();

    expect($teardownCalls)->toBe(1);
});

it('still runs final teardown after a fresh-clone rebuild failure', function (): void {
    $oldOperator = m::mock(E2EInstance::class);
    $oldOperator->shouldReceive('delete')->once();

    $teardownCalls = 0;

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: $oldOperator,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: fn (): array => throw new RuntimeException('start failed'),
        teardown: function () use (&$teardownCalls): void {
            $teardownCalls++;
        },
    );

    expect(fn () => $lease->reset())->toThrow(RuntimeException::class, 'start failed');

    $lease->cleanup();
    $lease->cleanup();

    expect($teardownCalls)->toBe(1);
});

it('fresh-clone reset uses a prepared rebuild state', function (): void {
    $oldOperator = m::mock(E2EInstance::class);
    $oldOperator->shouldReceive('delete')->once();

    $newOperator = m::mock(E2EInstance::class);

    $prepared = false;
    $rebuild = function () use ($newOperator, &$prepared): array {
        $prepared = true;

        return [
            'instances' => [
                'operator' => $newOperator,
            ],
            'snapshotReset' => null,
        ];
    };

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: $oldOperator,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: $rebuild,
    );

    $lease->reset();

    expect($prepared)->toBeTrue()
        ->and($lease->operator())->toBe($newOperator);
});

it('falls back to fresh-clone for unknown reset mode values', function (): void {
    $previous = getenv('ORBIT_E2E_TOPOLOGY_RESET');
    putenv('ORBIT_E2E_TOPOLOGY_RESET=unknown-mode');

    try {
        $oldOperator = m::mock(E2EInstance::class);
        $oldOperator->shouldReceive('delete')->once();

        $newOperator = m::mock(E2EInstance::class);

        $rebuild = fn (): array => [
            'instances' => ['operator' => $newOperator],
            'snapshotReset' => null,
        ];

        $lease = new E2ETopologyLease(
            kind: E2ETopologyKind::Operator,
            operator: $oldOperator,
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
            rebuild: $rebuild,
        );

        $lease->reset();

        expect($lease->operator())->toBe($newOperator);
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_TOPOLOGY_RESET');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_RESET={$previous}");
        }
    }
});
