<?php

declare(strict_types=1);

namespace App\Services\Nodes\Access;

use App\Enums\Nodes\NodeRoleName;
use InvalidArgumentException;

final class NodePermissionPresets
{
    /**
     * @return list<string>
     */
    public function permissions(string $name): array
    {
        return match ($name) {
            'agent-self' => $this->agentSelf(),
            'vpn-self' => $this->vpnSelf(),
            'app-dev-self' => $this->appDevelopmentSelf(),
            'app-prod-self' => $this->appProductionSelf(),
            'database-self' => $this->databaseSelf(),
            'operator' => $this->operator(),
            'read-only' => $this->readOnly(),
            'developer' => $this->developer(),
            'admin' => $this->admin(),
            'gateway-admin' => ['*'],
            default => throw new InvalidArgumentException("Unknown preset [{$name}]."),
        };
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return [
            'agent-self',
            'vpn-self',
            'app-dev-self',
            'app-prod-self',
            'database-self',
            'operator',
            'read-only',
            'developer',
            'admin',
            'gateway-admin',
        ];
    }

    public function selfPresetNameForRole(string|NodeRoleName $role): ?string
    {
        $roleName = $role instanceof NodeRoleName ? $role->value : $role;

        return match ($roleName) {
            NodeRoleName::Vpn->value => 'vpn-self',
            NodeRoleName::AppDevelopment->value => 'app-dev-self',
            NodeRoleName::AppProduction->value => 'app-prod-self',
            NodeRoleName::Database->value => 'database-self',
            NodeRoleName::Agent->value => 'agent-self',
            default => null,
        };
    }

    /**
     * Preset used by agent self grants.
     *
     * @return list<string>
     */
    private function agentSelf(): array
    {
        return [
            'doctor:verify',
            'node:read',
            'tool:read',
            'tool:update:agent-tools',
        ];
    }

    /**
     * @return list<string>
     */
    private function vpnSelf(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    private function appDevelopmentSelf(): array
    {
        return [
            'workspace:setup',
        ];
    }

    /**
     * @return list<string>
     */
    private function appProductionSelf(): array
    {
        return [
            'workspace:setup',
        ];
    }

    /**
     * @return list<string>
     */
    private function databaseSelf(): array
    {
        return [];
    }

    /**
     * Default cross-node preset for agent nodes and general-purpose
     * fleet operators.
     *
     * @return list<string>
     */
    private function operator(): array
    {
        return [
            'app:read',
            'database:read',
            'doctor:verify',
            'firewall_rule:read',
            'node:read',
            'tool:read',
        ];
    }

    /**
     * Preset that grants only read permissions across the product surface.
     *
     * @return list<string>
     */
    private function readOnly(): array
    {
        return [
            'activity:read',
            'app:read',
            'cf:dns:list',
            'cf:zone:list',
            'database:read',
            'deploy:read',
            'dns:list',
            'dns:resolve',
            'doctor:verify',
            'firewall_rule:read',
            'node:read',
            'php:read',
            'process:read',
            'proxy:read',
            'role:read',
            'schedule:read',
            'tool:read',
            'vpn:read',
            'workspace:read',
        ];
    }

    /**
     * Preset for developer workflows on app-dev nodes.
     *
     * @return list<string>
     */
    private function developer(): array
    {
        return [
            'app:read',
            'app:write',
            'app:register',
            'app:remove',
            'app:prune',
            'app:agent',
            'app:root',
            'app:update',
            'app:new',
            'app:worker',
            'app:mount',
            'workspace:read',
            'workspace:write',
            'workspace:new',
            'workspace:setup',
            'workspace:remove',
            'workspace:history',
            'workspace:log',
            'process:read',
            'process:add',
            'process:edit',
            'process:remove',
            'process:start',
            'process:stop',
            'process:restart',
            'schedule:read',
            'schedule:add',
            'schedule:remove',
            'schedule:run',
            'schedule:write',
            'proxy:read',
            'proxy:add',
            'proxy:remove',
            'deploy:read',
            'deploy:run',
            'deploy:step',
            'database:read',
            'database:write',
            'database:query',
            'database:query:write',
            'tool:read',
            'tool:update',
            'tool:update:agent-tools',
            'tool:install',
            'tool:remove',
            'tool:reconfigure',
            'agent-ide:message',
            'node:read',
            'doctor:verify',
            'dns:list',
            'dns:add',
            'dns:remove',
            'dns:resolve',
        ];
    }

    /**
     * Preset that grants full administrative authority over a serving node
     * short of fleet-wide gateway admin.
     *
     * @return list<string>
     */
    private function admin(): array
    {
        return [
            // Activity
            'activity:read',
            'activity:list',
            'activity:show',

            // Agent IDE
            'agent-ide:message',

            // App
            'app:credentials',
            'app:read',
            'app:write',
            'app:register',
            'app:remove',
            'app:prune',
            'app:agent',
            'app:root',
            'app:update',
            'app:new',
            'app:worker',
            'app:mount',

            // Cloudflare
            'cf:cache:flush',
            'cf:cache:rule:add',
            'cf:cache:rule:remove',
            'cf:dns:add',
            'cf:dns:list',
            'cf:dns:remove',
            'cf:ssl:disable',
            'cf:ssl:enable',
            'cf:zone:list',

            // Deploy
            'deploy:read',
            'deploy:run',
            'deploy:step',
            'deploy:history',
            'deploy:log',

            // Database
            'database:read',
            'database:write',
            'database:query',
            'database:query:write',

            // DNS
            'dns:add',
            'dns:list',
            'dns:remove',
            'dns:resolve',

            // Doctor
            'doctor:verify',
            'doctor:restore',
            'doctor:adopt',
            'doctor:fix',

            // Firewall
            'firewall_rule:read',
            'firewall_rule:write',

            // Node (read only)
            'node:read',

            // PHP
            'php:read',
            'php:write',
            'php:list',
            'php:use',

            // Process
            'process:read',
            'process:add',
            'process:edit',
            'process:remove',
            'process:start',
            'process:stop',
            'process:restart',

            // Proxy
            'proxy:read',
            'proxy:add',
            'proxy:remove',

            // Schedule
            'schedule:read',
            'schedule:add',
            'schedule:remove',
            'schedule:run',
            'schedule:write',

            // Tool
            'tool:read',
            'tool:update',
            'tool:update:agent-tools',
            'tool:install',
            'tool:remove',
            'tool:reconfigure',
            'tool:credentials',

            // VPN
            'vpn:read',
            'vpn:write',

            // Workspace
            'workspace:read',
            'workspace:write',
            'workspace:new',
            'workspace:setup',
            'workspace:remove',
            'workspace:history',
            'workspace:log',
        ];
    }
}
