<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Data\Nodes\RoleSettings\AppProductionRoleSettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Runtime\OrbitCaddyContainer;
use DomainException;

final readonly class IngressResolver
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function forAppNode(Node $appNode): Node
    {
        $assignment = $this->appProductionAssignment($appNode);
        $settings = AppProductionRoleSettings::fromArray($assignment->settings ?? []);
        $ingressNodeId = $settings->ingressNodeId;

        if ($ingressNodeId === null) {
            if ($this->nodeRoleAssignments->nodeCanServeIngress($appNode)) {
                return $appNode;
            }

            throw new DomainException('The selected ingress node is unavailable.');
        }

        $ingressNode = Node::query()->find($ingressNodeId);

        if (! $ingressNode instanceof Node || ! $this->nodeRoleAssignments->nodeCanServeIngress($ingressNode)) {
            throw new DomainException('The selected ingress node is unavailable.');
        }

        return $ingressNode;
    }

    public function backendUrl(Node $appNode): string
    {
        $wireguardAddress = is_string($appNode->wireguard_address) ? trim($appNode->wireguard_address) : '';

        if ($wireguardAddress === '') {
            throw new DomainException('App-production backend node requires a WireGuard address for ingress.');
        }

        $port = OrbitCaddyContainer::PrivateBackendPort;

        return "http://{$wireguardAddress}:{$port}";
    }

    public function router(): Node
    {
        $router = $this->nodeRoleAssignments->activeRouterNodeQuery()
            ->orderBy('id')
            ->first();

        if (! $router instanceof Node) {
            throw new DomainException('An active router node is required for ingress.');
        }

        return $router;
    }

    public function routerUrl(Node $router): string
    {
        $wireguardAddress = is_string($router->wireguard_address) ? trim($router->wireguard_address) : '';

        if ($wireguardAddress === '') {
            throw new DomainException('Router node requires a WireGuard address for ingress.');
        }

        return "http://{$wireguardAddress}:80";
    }

    private function appProductionAssignment(Node $appNode): NodeRoleAssignment
    {
        $assignment = $this->nodeRoleAssignments->activeAssignment($appNode, 'app-prod');

        if (! $assignment instanceof NodeRoleAssignment) {
            throw new DomainException("Node '{$appNode->name}' is not an active app-prod node.");
        }

        return $assignment;
    }
}
