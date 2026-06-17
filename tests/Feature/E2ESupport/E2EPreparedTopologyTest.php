<?php

declare(strict_types=1);

use App\E2E\Support\DockerTopologyBuilder;
use App\E2E\Support\DockerTopologyProvider;
use App\E2E\Support\E2EPreparedTopology;
use App\E2E\Support\E2ETopologyArtifactNamespace;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusTopologyTemplate;

it('maps Incus topology requests to the websocket-capable prepared full source artifact', function (): void {
    expect(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::Operator))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGateway))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGatewayAppdev))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGatewayAppdevAppprod))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGatewayAgent))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGatewayAppprodIngress))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGatewayAppdevWebsocket))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::sourceKindFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket))->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);
});

it('maps Docker topology requests to composable role image sources', function (): void {
    expect(E2EPreparedTopology::dockerArtifactSourceKindsFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent))
        ->toBe([E2ETopologyKind::OperatorGateway, E2ETopologyKind::OperatorGatewayAppdevAppprodAgent])
        ->and(E2EPreparedTopology::dockerArtifactSourceKindsFor(E2ETopologyKind::OperatorGatewayAgent))
        ->toBe([E2ETopologyKind::OperatorGateway, E2ETopologyKind::OperatorGatewayAppdevAppprodAgent])
        ->and(E2EPreparedTopology::dockerArtifactSourceKindsFor(E2ETopologyKind::OperatorGatewayAppprodIngress))
        ->toBe([E2ETopologyKind::OperatorGateway, E2ETopologyKind::OperatorGatewayAppdevAppprodAgent])
        ->and(E2EPreparedTopology::dockerArtifactSourceKindsFor(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress))
        ->toBe([E2ETopologyKind::OperatorGateway, E2ETopologyKind::OperatorGatewayAppdevAppprodAgent, E2ETopologyKind::OperatorGatewayAppdevAppprodIngress])
        ->and(E2EPreparedTopology::dockerArtifactSourceKindsFor(E2ETopologyKind::OperatorGatewayAppdevWebsocket))
        ->toBe([E2ETopologyKind::OperatorGateway, E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket])
        ->and(E2EPreparedTopology::dockerArtifactSourceKindsFor(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket))
        ->toBe([E2ETopologyKind::OperatorGateway, E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket])
        ->and(E2EPreparedTopology::dockerArtifactSourceKindsFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket))
        ->toBe([E2ETopologyKind::OperatorGateway, E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket])
        ->and(E2EPreparedTopology::dockerArtifactSourceKindsFor(E2ETopologyKind::OperatorGateway))
        ->toBe([E2ETopologyKind::OperatorGateway])
        ->and(E2EPreparedTopology::dockerArtifactSourceKindsFor(E2ETopologyKind::Operator))
        ->toBe([E2ETopologyKind::OperatorGateway]);

    expect(DockerTopologyBuilder::imageNameFor(E2ETopologyKind::OperatorGatewayAgent, 'operator'))
        ->toBe('orbit-e2e:operator_base')
        ->and(DockerTopologyBuilder::imageNameFor(E2ETopologyKind::OperatorGatewayAgent, 'gateway'))
        ->toBe('orbit-e2e:gateway_base')
        ->and(DockerTopologyBuilder::imageNameFor(E2ETopologyKind::OperatorGatewayAgent, 'agent'))
        ->toBe('orbit-e2e:agent_base')
        ->and(DockerTopologyBuilder::imageNameFor(E2ETopologyKind::OperatorGatewayAppprodIngress, 'prod'))
        ->toBe('orbit-e2e:app-prod_base');
});

it('sources Incus downstream roles from the websocket-capable prepared full snapshot', function (): void {
    expect(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::Operator))
        ->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGateway))
        ->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGatewayAppdev))
        ->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGatewayAgent))
        ->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGatewayAppprodIngress))
        ->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress))
        ->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress)
        ->and(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGatewayAppdevWebsocket))
        ->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket))
        ->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket)
        ->and(E2EPreparedTopology::incusSourceKindFor(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket))
        ->toBe(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket);
});

it('collapses app production ingress onto the prod role', function (): void {
    expect(E2EPreparedTopology::runtimeRolesFor(
        E2ETopologyKind::OperatorGatewayAppprodIngress,
        ['operator', 'gateway', 'prod', 'ingress'],
    ))->toBe(['operator', 'gateway', 'prod'])
        ->and(E2EPreparedTopology::prodHostsIngressRole(E2ETopologyKind::OperatorGatewayAppprodIngress))->toBeTrue()
        ->and(E2EPreparedTopology::prodHostsIngressRole(E2ETopologyKind::OperatorGatewayAppdevAppprodIngress))->toBeFalse()
        ->and(E2EPreparedTopology::prodHostsIngressRole(E2ETopologyKind::OperatorGatewayAppdevAppprod))->toBeTrue()
        ->and(E2EPreparedTopology::prodHostsIngressRole(E2ETopologyKind::OperatorGatewayAppdevAppprodAgent))->toBeTrue()
        ->and(E2EPreparedTopology::prodHostsIngressRole(E2ETopologyKind::OperatorGatewayAppdevWebsocket))->toBeFalse()
        ->and(E2EPreparedTopology::prodHostsIngressRole(E2ETopologyKind::OperatorGatewayAppdevAppprodWebsocket))->toBeTrue()
        ->and(E2EPreparedTopology::prodHostsIngressRole(E2ETopologyKind::OperatorGatewayAppdevAppprodAgentWebsocket))->toBeTrue();
});

it('builds a gateway registry prune script that removes stale topology rows', function (): void {
    $script = E2EPreparedTopology::gatewayRegistryPrunePhp(['gateway', 'operator-1']);

    expect($script)
        ->toContain("['gateway', 'operator-1']")
        ->toContain('whereNotIn')
        ->toContain('FirewallRule::query()')
        ->toContain('ProxyRoute::query()')
        ->toContain('App::query()')
        ->toContain('Node::query()')
        ->not->toContain('OPERATOR_STORAGE_ROLE');
});

it('builds a gateway registry prune script that removes stale role assignments on retained nodes', function (): void {
    $allowedRoles = E2EPreparedTopology::gatewayAllowedRoleAssignmentsFor(
        E2ETopologyKind::OperatorGatewayAppdev,
        ['operator', 'gateway', 'dev'],
    );
    $script = E2EPreparedTopology::gatewayRegistryPrunePhp(
        ['gateway', 'operator-1', 'app-dev-1'],
        $allowedRoles,
    );

    expect($allowedRoles)->toBe([
        'app-dev-1' => ['app-dev', 'database'],
    ])
        ->and($script)->toContain('$allowedRolesByNode')
        ->and($script)->toContain("'app-dev-1' => ['app-dev', 'database']")
        ->and($script)->toContain("whereNotIn('role', \$allowedRoles)")
        ->and($script)->not->toContain('websocket');
});

it('does not retain a split ingress node when app production ingress boots only the prod role', function (): void {
    expect(E2EPreparedTopology::gatewayNodeNamesForRoles(['operator', 'gateway', 'prod']))
        ->toBe(['gateway', 'operator-1', 'app-prod-1'])
        ->not->toContain('edge-1');
});

it('keeps websocket topology registry pruning on the app-dev node', function (): void {
    expect(E2EPreparedTopology::gatewayNodeNamesForRoles(['operator', 'gateway', 'dev', 'websocket']))
        ->toBe(['gateway', 'operator-1', 'app-dev-1'])
        ->and(E2EPreparedTopology::gatewayAllowedRoleAssignmentsFor(
            E2ETopologyKind::OperatorGatewayAppdevWebsocket,
            ['operator', 'gateway', 'dev'],
        ))->toBe([
            'app-dev-1' => ['app-dev', 'database', 'websocket'],
        ]);
});

it('keeps production ingress role assignments on app-prod when production hosts ingress', function (): void {
    expect(E2EPreparedTopology::gatewayAllowedRoleAssignmentsFor(
        E2ETopologyKind::OperatorGatewayAppprodIngress,
        ['operator', 'gateway', 'prod'],
    ))->toBe([
        'app-prod-1' => ['app-prod', 'ingress'],
    ]);
});

it('normalizes prepared artifact role selections', function (): void {
    expect(E2EPreparedTopology::parseArtifactRoles('operator, gateway, dev, prod, ingress, agent'))
        ->toBe(['operator', 'gateway', 'app-dev', 'app-prod', 'ingress', 'agent'])
        ->and(E2EPreparedTopology::parseArtifactRoles('operator,app-dev,app-prod,ingress,agent,operator'))
        ->toBe(['operator', 'app-dev', 'app-prod', 'ingress', 'agent'])
        ->and(E2EPreparedTopology::dockerRoleForArtifactRole('app-dev'))
        ->toBe('dev')
        ->and(E2EPreparedTopology::incusRoleForArtifactRole('ingress'))
        ->toBe('ingress')
        ->and(E2EPreparedTopology::incusRoleForArtifactRole('operator'))
        ->toBe('operator');
});

it('rejects unknown prepared artifact roles', function (): void {
    expect(fn () => E2EPreparedTopology::parseArtifactRoles('operator,edge-cache'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported prepared artifact role [edge-cache].');
});

it('uses a separate topology artifact namespace by default', function (): void {
    expect(DockerTopologyBuilder::imageNameFor(E2ETopologyKind::OperatorGateway, 'operator', 'dns-alias'))
        ->toBe('orbit-e2e:operator_base')
        ->and(DockerTopologyBuilder::runtimeImage())
        ->toBe('orbit-e2e-topology-runtime:prepared-current')
        ->and(DockerTopologyProvider::gatewaySiblingImage())
        ->toBe('orbit-gateway:prepared-current')
        ->and(DockerTopologyProvider::gatewayImage())
        ->toBe('orbit-gateway:prepared-current')
        ->and(E2ETopologyArtifactNamespace::dockerBuildName('orbit-e2e', E2ETopologyKind::OperatorGateway))
        ->toBe('orbit-e2e-prepared-build-operator_gateway')
        ->and(E2ETopologyArtifactNamespace::runtimeInstancePrefix('orbit-e2e'))
        ->toBe('orbit-e2e-prepared')
        ->and(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGateway, 'operator'))
        ->toBe('orbit-template-operator-base')
        ->and(IncusTopologyTemplate::snapshotName(E2ETopologyKind::OperatorGateway))
        ->toBe('clean-operator_gateway-base');
});

it('allows a custom topology artifact namespace for isolated benchmark runs', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE' => 'Branch A/B',
    ], function (): void {
        expect(DockerTopologyBuilder::imageNameFor(E2ETopologyKind::OperatorGateway, 'operator'))
            ->toBe('orbit-e2e:operator_branch-a-b')
            ->and(DockerTopologyBuilder::runtimeImage())
            ->toBe('orbit-e2e-topology-runtime:branch-a-b-current')
            ->and(DockerTopologyProvider::gatewaySiblingImage())
            ->toBe('orbit-gateway:branch-a-b-current')
            ->and(DockerTopologyProvider::gatewayImage())
            ->toBe('orbit-gateway:branch-a-b-current')
            ->and(E2ETopologyArtifactNamespace::runtimeInstancePrefix('orbit-e2e'))
            ->toBe('orbit-e2e-branch-a-b')
            ->and(IncusTopologyTemplate::templateName(E2ETopologyKind::OperatorGateway, 'operator'))
            ->toBe('orbit-template-operator-branch-a-b')
            ->and(IncusTopologyTemplate::snapshotName(E2ETopologyKind::OperatorGateway))
            ->toBe('clean-operator_gateway-branch-a-b');
    });
});
