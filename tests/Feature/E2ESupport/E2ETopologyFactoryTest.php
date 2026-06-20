<?php

declare(strict_types=1);

use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ETopologyCapabilities;
use App\E2E\Support\E2ETopologyFactory;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\E2ETopologyUnavailable;
use App\E2E\Support\SshKeyPair;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

it('has correct enum string values', function (): void {
    expect(E2ETopologyKind::Operator->value)->toBe('operator')
        ->and(E2ETopologyKind::OperatorGateway->value)->toBe('operator_gateway')
        ->and(E2ETopologyKind::OperatorGatewayAppdev->value)->toBe('operator_gateway_app-dev')
        ->and(E2ETopologyKind::OperatorGatewayAppdevAppprod->value)->toBe('operator_gateway_app-dev_app-prod')
        ->and(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress->value)->toBe('operator_gateway_app-dev_app-prod_ingress')
        ->and(E2ETopologyKind::OperatorGatewayAgent->value)->toBe('operator_gateway_agent')
        ->and(E2ETopologyKind::tryFromInput('operator_gateway_app-prod_ingress')?->value)->toBe('operator_gateway_app-prod_ingress')
        ->and(E2ETopologyKind::tryFromInput('operator_gateway_app-dev_websocket')?->value)->toBe('operator_gateway_app-dev_websocket')
        ->and(E2ETopologyKind::tryFromInput('operator_gateway_app-dev_app-prod_websocket')?->value)->toBe('operator_gateway_app-dev_app-prod_websocket')
        ->and(E2ETopologyKind::tryFromInput('operator_gateway_app-dev_app-prod_agent_websocket')?->value)->toBe('operator_gateway_app-dev_app-prod_agent_websocket')
        ->and(E2ETopologyKind::Operator)->toBe(E2ETopologyKind::Operator)
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-agent'))->toBe(E2ETopologyKind::OperatorGatewayAgent)
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-dev-prod'))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprod)
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-dev-prod-ingress'))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress)
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-dev-websocket'))->toBe(E2ETopologyKind::OperatorGatewayAppdevWebsocket)
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-appdev-websocket'))->toBe(E2ETopologyKind::OperatorGatewayAppdevWebsocket)
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-dev-prod-websocket'))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket)
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-appdev-appprod-websocket'))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket)
        ->and(E2ETopologyKind::tryFromInput('operator-gateway-appdev-appprod-agent-websocket'))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);
});

it('resolves requested topology kinds exactly', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment();
        $ingressKind = E2ETopologyKind::tryFromInput('operator_gateway_app-prod_ingress');

        expect($ingressKind)->not->toBeNull();
        expect($factory->resolveKind(E2ETopologyKind::OperatorGateway))->toBe(E2ETopologyKind::OperatorGateway)
            ->and($factory->resolveKind(E2ETopologyKind::Operator))->toBe(E2ETopologyKind::Operator)
            ->and($factory->resolveKind(E2ETopologyKind::OperatorGatewayAppdev))->toBe(E2ETopologyKind::OperatorGatewayAppdev)
            ->and($factory->resolveKind(E2ETopologyKind::OperatorGatewayAppdevAppprod))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprod)
            ->and($factory->resolveKind(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress)
            ->and($factory->resolveKind(E2ETopologyKind::OperatorGatewayAgent))->toBe(E2ETopologyKind::OperatorGatewayAgent)
            ->and($factory->resolveKind(E2ETopologyKind::OperatorGatewayAppdevWebsocket))->toBe(E2ETopologyKind::OperatorGatewayAppdevWebsocket)
            ->and($factory->resolveKind(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket)
            ->and($factory->resolveKind(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
            ->and($factory->resolveKind($ingressKind))->toBe($ingressKind);
    });
});

it('reports unavailable topology when requiring a topology', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_INCUS_HOSTS' => 'orbit-e2e-nonexistent.invalid',
    ], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment();

        expect(fn () => $factory->require(E2ETopologyKind::Operator))
            ->toThrow(E2ETopologyUnavailable::class, 'incus: prepared topology operator is not available on any Incus host: orbit-e2e-nonexistent.invalid is missing prepared templates or snapshots');
    });
});

it('reports topology provider failure details when no prepared provider is available', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'incus',
        'ORBIT_E2E_INCUS_HOSTS' => 'orbit-e2e-nonexistent.invalid',
    ], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment();

        expect(fn () => $factory->require(E2ETopologyKind::Operator))
            ->toThrow(E2ETopologyUnavailable::class, 'Prepared topology not available');
    });
});

it('fails topology helper acquisition in strict lane mode', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_CACHE' => '0',
        'ORBIT_E2E_FAIL_ON_TOPOLOGY_UNAVAILABLE' => '1',
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'incus',
        'ORBIT_E2E_INCUS_HOSTS' => 'orbit-e2e-nonexistent.invalid',
    ], function (): void {
        expect(fn () => e2eTopology(E2ETopologyKind::Operator))
            ->toThrow(E2ETopologyUnavailable::class, 'Prepared topology not available');
    });
});

it('refuses providers that do not satisfy required capabilities', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'docker',
    ], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment()
            ->requireCapabilities(new E2ETopologyCapabilities(
                realSsh: true,
                systemd: false,
                hostMutation: false,
                kernelNetworking: false,
            ));

        expect(fn () => $factory->require(E2ETopologyKind::Operator))
            ->toThrow(E2ETopologyUnavailable::class, 'capabilities do not satisfy required');
    });
});

it('lease cleanup is idempotent', function (): void {
    $operator = m::mock(E2EInstance::class);
    $operator->shouldReceive('delete')->once();

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: $operator,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: fn () => [],
    );

    $lease->cleanup();
    $lease->cleanup();
});

it('stores requested SSH users immutably', function (): void {
    $factory = E2ETopologyFactory::fromEnvironment();
    $operatorOnly = $factory->withSshUsers(['operator' => 'operator']);

    expect($operatorOnly)->not->toBe($factory)
        ->and((new ReflectionClass($operatorOnly))->getProperty('sshUsers')->getValue($operatorOnly))
        ->toBe(['operator' => 'operator'])
        ->and((new ReflectionClass($factory))->getProperty('sshUsers')->getValue($factory))
        ->toBeNull();
});

it('stores source mounted checkout acquisition options immutably', function (): void {
    $factory = E2ETopologyFactory::fromEnvironment()
        ->withSshUsers(['operator' => 'operator'])
        ->withGatewayApi();

    $sourceDevFactory = $factory->withSourceMountedCheckout();

    $optionsMethod = new ReflectionMethod($sourceDevFactory, 'acquisitionOptions');
    $optionsMethod->setAccessible(true);
    $options = $optionsMethod->invoke($sourceDevFactory);

    expect($sourceDevFactory)->not->toBe($factory)
        ->and((new ReflectionClass($sourceDevFactory))->getProperty('sourceMountedCheckout')->getValue($sourceDevFactory))
        ->toBeTrue()
        ->and((new ReflectionClass($factory))->getProperty('sourceMountedCheckout')->getValue($factory))
        ->toBeFalse()
        ->and($options->sshUsers)->toBe(['operator' => 'operator'])
        ->and($options->startGatewayApi)->toBeTrue()
        ->and($options->sourceMountedCheckout)->toBeTrue();
});

it('cleans up all instances', function (): void {
    $operator = m::mock(E2EInstance::class);
    $gateway = m::mock(E2EInstance::class);
    $dev = m::mock(E2EInstance::class);
    $prod = m::mock(E2EInstance::class);

    $operator->shouldReceive('delete')->once();
    $gateway->shouldReceive('delete')->once();
    $dev->shouldReceive('delete')->once();
    $prod->shouldReceive('delete')->once();

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdevAppprod,
        operator: $operator,
        gateway: $gateway,
        dev: $dev,
        prod: $prod,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: fn () => [],
    );

    $lease->cleanup();
});
