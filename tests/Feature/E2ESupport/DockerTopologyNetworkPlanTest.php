<?php

declare(strict_types=1);

use App\E2E\Support\DockerTopologyNetworkPlan;

it('keeps non-run docker topology networks outside the orbit WireGuard subnet', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment();

        expect($plan->subnet())->toBe('10.90.224.0/24')
            ->and($plan->ipForRole('gateway'))->toBe('10.90.224.2')
            ->and($plan->ipForRole('operator'))->toBe('10.90.224.3')
            ->and($plan->ipForRole('operator'))->toBe('10.90.224.3')
            ->and($plan->ipForRole('dev'))->toBe('10.90.224.4')
            ->and($plan->ipForRole('prod'))->toBe('10.90.224.5')
            ->and($plan->ipForRole('agent'))->toBe('10.90.224.6')
            ->and($plan->ipForRole('ingress'))->toBe('10.90.224.7')
            ->and($plan->ipForRole('websocket'))->toBe('10.90.224.8');
    } finally {
        restoreTestToken($previous);
    }
});

it('allocates a run-scoped docker subnet outside parallel workers', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment('run123');

        expect($plan->subnet())->toBe('10.90.166.0/24')
            ->and($plan->ipForRole('gateway'))->toBe('10.90.166.2')
            ->and($plan->ipForRole('operator'))->toBe('10.90.166.3')
            ->and($plan->ipForRole('operator'))->toBe('10.90.166.3')
            ->and($plan->ipForRole('dev'))->toBe('10.90.166.4')
            ->and($plan->ipForRole('prod'))->toBe('10.90.166.5')
            ->and($plan->ipForRole('websocket'))->toBe('10.90.166.8');
    } finally {
        restoreTestToken($previous);
    }
});

it('allocates a distinct docker subnet for each parallel worker token', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=2');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment();

        expect($plan->subnet())->toBe('10.90.226.0/24')
            ->and($plan->ipForRole('gateway'))->toBe('10.90.226.2')
            ->and($plan->ipForRole('operator'))->toBe('10.90.226.3')
            ->and($plan->ipForRole('operator'))->toBe('10.90.226.3')
            ->and($plan->ipForRole('dev'))->toBe('10.90.226.4')
            ->and($plan->ipForRole('prod'))->toBe('10.90.226.5')
            ->and($plan->ipForRole('websocket'))->toBe('10.90.226.8');
    } finally {
        restoreTestToken($previous);
    }
});

it('allocates a run-scoped docker subnet for parallel topology leases', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=2');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment('run123');

        expect($plan->subnet())->toBe('10.90.26.0/24')
            ->and($plan->ipForRole('gateway'))->toBe('10.90.26.2')
            ->and($plan->ipForRole('operator'))->toBe('10.90.26.3')
            ->and($plan->ipForRole('operator'))->toBe('10.90.26.3')
            ->and($plan->ipForRole('dev'))->toBe('10.90.26.4')
            ->and($plan->ipForRole('prod'))->toBe('10.90.26.5')
            ->and($plan->ipForRole('websocket'))->toBe('10.90.26.8');
    } finally {
        restoreTestToken($previous);
    }
});

it('can advance to the next run-scoped docker subnet after an overlap', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=2');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment('run123', attempt: 1);

        expect($plan->subnet())->toBe('10.90.27.0/24')
            ->and($plan->ipForRole('gateway'))->toBe('10.90.27.2')
            ->and($plan->ipForRole('operator'))->toBe('10.90.27.3');
    } finally {
        restoreTestToken($previous);
    }
});

it('supports sixteen run-scoped docker workers for expanded runner pools', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=16');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment('run123');

        expect($plan->subnet())->toBe('10.90.222.0/24')
            ->and($plan->ipForRole('gateway'))->toBe('10.90.222.2');
    } finally {
        restoreTestToken($previous);
    }
});

it('rejects run-scoped docker workers above the allocator capacity', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=17');

    try {
        DockerTopologyNetworkPlan::fromEnvironment('run123');
    } finally {
        restoreTestToken($previous);
    }
})->throws(RuntimeException::class, 'Unsupported parallel test token [17] for run-scoped Docker E2E subnet allocation.');

function restoreTestToken(string|false $previous): void
{
    if ($previous === false) {
        putenv('TEST_TOKEN');

        return;
    }

    putenv("TEST_TOKEN={$previous}");
}
