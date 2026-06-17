<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\RoleBaselines\AgentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppDevelopmentRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\AppProductionRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\DatabaseRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\GatewayRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\IngressRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\MetricsRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\RoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\RouterRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\S3RoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\VpnRoleBaseline;
use App\Services\Nodes\Roles\RoleBaselines\WebSocketRoleBaseline;
use InvalidArgumentException;

class NodeRoleBaselineConverger
{
    public function __construct(
        private readonly GatewayRoleBaseline $gatewayRoleBaseline,
        private readonly AppDevelopmentRoleBaseline $appDevelopmentRoleBaseline,
        private readonly AppProductionRoleBaseline $appProductionRoleBaseline,
        private readonly DatabaseRoleBaseline $databaseRoleBaseline,
        private readonly AgentRoleBaseline $agentRoleBaseline,
        private readonly ?RouterRoleBaseline $routerRoleBaseline = null,
        private readonly ?IngressRoleBaseline $ingressRoleBaseline = null,
        private readonly ?VpnRoleBaseline $vpnRoleBaseline = null,
        private readonly ?WebSocketRoleBaseline $webSocketRoleBaseline = null,
        private readonly ?S3RoleBaseline $s3RoleBaseline = null,
        private readonly ?MetricsRoleBaseline $metricsRoleBaseline = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        $this->baseline($assignment->role)->converge($node, $assignment);
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $this->baseline($assignment->role)->remove($node, $assignment, $purgeData);
    }

    protected function baseline(string $role): RoleBaseline
    {
        return match ($role) {
            NodeRoleName::Gateway->value => $this->gatewayRoleBaseline,
            NodeRoleName::Vpn->value => $this->vpnRoleBaseline(),
            NodeRoleName::Router->value => $this->routerRoleBaseline(),
            NodeRoleName::AppDevelopment->value => $this->appDevelopmentRoleBaseline,
            NodeRoleName::AppProduction->value => $this->appProductionRoleBaseline,
            NodeRoleName::Database->value => $this->databaseRoleBaseline,
            NodeRoleName::Agent->value => $this->agentRoleBaseline,
            NodeRoleName::Ingress->value => $this->ingressRoleBaseline(),
            NodeRoleName::WebSocket->value => $this->webSocketRoleBaseline(),
            NodeRoleName::S3->value => $this->s3RoleBaseline(),
            NodeRoleName::Metrics->value => $this->metricsRoleBaseline(),
            default => throw new InvalidArgumentException("Unsupported node role baseline [{$role}]."),
        };
    }

    protected function ingressRoleBaseline(): IngressRoleBaseline
    {
        return $this->ingressRoleBaseline ?? app(IngressRoleBaseline::class);
    }

    protected function routerRoleBaseline(): RouterRoleBaseline
    {
        return $this->routerRoleBaseline ?? app(RouterRoleBaseline::class);
    }

    protected function vpnRoleBaseline(): VpnRoleBaseline
    {
        return $this->vpnRoleBaseline ?? app(VpnRoleBaseline::class);
    }

    protected function webSocketRoleBaseline(): WebSocketRoleBaseline
    {
        return $this->webSocketRoleBaseline ?? app(WebSocketRoleBaseline::class);
    }

    protected function s3RoleBaseline(): S3RoleBaseline
    {
        return $this->s3RoleBaseline ?? app(S3RoleBaseline::class);
    }

    protected function metricsRoleBaseline(): MetricsRoleBaseline
    {
        return $this->metricsRoleBaseline ?? app(MetricsRoleBaseline::class);
    }
}
