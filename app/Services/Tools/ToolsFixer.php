<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Convergence\ManagedFile;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\ProxyRouteRenderer;
use InvalidArgumentException;

final readonly class ToolsFixer
{
    public function __construct(
        private RemoteShell $remoteShell,
        private ?ToolCatalog $catalog = null,
        private ?ProxyRouteRenderer $proxyRouteRenderer = null,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(NodeTool $tool, DriftEntry $entry): ?array
    {
        $tool->loadMissing('node');

        if ($tool->node === null) {
            return null;
        }

        $result = match ($entry->key) {
            'tool.config_missing', 'tool.config_mismatch' => $this->runRepairCommand($tool, $this->configRepairCommand($tool), $entry),
            'tool.credentials_missing', 'tool.credentials_mismatch' => $this->runRepairCommand($tool, $this->secretRepairCommand($tool), $entry),
            'tool.container_missing', 'tool.container_spec_mismatch' => $this->runRepairCommand($tool, $this->containerRepairCommand($tool), $entry),
            'tool.agent_route_missing' => $this->fixAgentRoute($tool, $entry),
            'tool.agent_credentials_missing' => $this->fixAgentCredentials($tool, $entry),
            'tool.agent_user_missing' => $this->fixAgentUser($tool, $entry),
            default => $this->runRepairCommand($tool, $this->repairCommand($tool, $entry), $entry),
        };

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runRepairCommand(NodeTool $tool, ?string $command, DriftEntry $entry): ?array
    {
        if ($command === null) {
            return null;
        }

        $this->remoteShell->run($tool->node, $command, ['throw' => true]);

        return $this->fixResult($tool, $entry);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixResult(NodeTool $tool, DriftEntry $entry): array
    {
        return [
            'family' => 'tool',
            'node' => $tool->node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Repaired tool {$tool->name} from gateway intent.",
            'details' => [
                'tool' => $tool->name,
            ],
        ];
    }

    private function repairCommand(NodeTool $tool, DriftEntry $entry): ?string
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);
        $config = $this->configForToolScript($tool);

        if ($entry->key === 'tool.capability_missing') {
            return $catalog->installScript($tool->name, $config);
        }

        if ($entry->key !== 'tool.version_mismatch') {
            return null;
        }

        $command = $catalog->updateScript($tool->name, $config);

        return is_string($command) && $command !== '' ? $command : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function configForToolScript(NodeTool $tool): array
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $tool->loadMissing('node');
        $managedUser = $tool->node?->user;

        return [
            ...$config,
            'managed_user' => is_string($managedUser) && trim($managedUser) !== '' ? trim($managedUser) : 'orbit',
        ];
    }

    private function containerRepairCommand(NodeTool $tool): ?string
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        return $catalog->updateScript($tool->name, is_array($tool->config) ? $tool->config : []);
    }

    private function configRepairCommand(NodeTool $tool): ?string
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $managedConfig = is_array($config['managed_config'] ?? null) ? $config['managed_config'] : [];

        try {
            return ManagedFile::fromIntent($managedConfig)->writeScript();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function secretRepairCommand(NodeTool $tool): ?string
    {
        $credentials = is_array($tool->credentials) ? $tool->credentials : [];
        $managedSecret = is_array($credentials['managed_secret'] ?? null) ? $credentials['managed_secret'] : [];

        try {
            return ManagedFile::fromIntent(
                intent: $managedSecret,
                defaultMode: '0600',
                defaultDirectoryMode: '0700',
                sensitive: true,
            )->writeScript();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fixAgentRoute(NodeTool $tool, DriftEntry $entry): ?array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if ($catalog->category($tool->name) !== 'agent') {
            return null;
        }

        $tld = $this->agentTldForNode($tool->node);

        if ($tld === null) {
            return null;
        }

        $domain = "{$tool->name}.{$tld}";

        $existing = ProxyRoute::query()
            ->where('domain', $domain)
            ->first();

        if ($existing instanceof ProxyRoute) {
            if ($existing->owner_type !== 'tool') {
                return null;
            }

            $existingOwner = is_array($existing->config) ? ($existing->config['owner_name'] ?? null) : null;

            if ($existingOwner !== $tool->name) {
                return null;
            }
        }

        $routeConfig = $this->agentProxyRouteConfig($tool->name);
        $sourceHash = $this->agentProxyRouteSourceHash($tool->node, $domain, $routeConfig);

        ProxyRoute::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'node_id' => $tool->node->id,
                'app_id' => null,
                'workspace_id' => null,
                'owner_type' => 'tool',
                'kind' => 'proxy',
                'config' => $routeConfig,
                'source_hash' => $sourceHash,
            ],
        );

        return $this->fixResult($tool, $entry);
    }

    /**
     * @return array{target: array{type: string, value: string}, upstream: string, owner_name: string}
     */
    private function agentProxyRouteConfig(string $tool): array
    {
        $upstream = 'http://'.ProxyRouteRenderer::HostLoopbackHostname.':8080';

        return [
            'target' => ['type' => 'upstream', 'value' => $upstream],
            'upstream' => $upstream,
            'owner_name' => $tool,
        ];
    }

    /**
     * @param  array{target: array{type: string, value: string}, upstream: string, owner_name: string}  $config
     */
    private function agentProxyRouteSourceHash(Node $node, string $domain, array $config): string
    {
        return ($this->proxyRouteRenderer ?? app(ProxyRouteRenderer::class))->sourceHash(new ProxyRoute([
            'node_id' => $node->id,
            'domain' => $domain,
            'kind' => 'proxy',
            'owner_type' => 'tool',
            'config' => $config,
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fixAgentCredentials(NodeTool $tool, DriftEntry $entry): ?array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if (! $catalog->hasCapability($tool->name, 'credentials')) {
            return null;
        }

        $tld = $this->agentTldForNode($tool->node);
        $hostname = $tld !== null ? "{$tool->name}.{$tld}" : $tool->name;
        $credentialsScript = $catalog->credentialsScript($tool->name, ['hostname' => $hostname]);

        if ($credentialsScript === null) {
            return null;
        }

        $credResult = $this->remoteShell->run($tool->node, $credentialsScript, ['throw' => false]);

        if (! $credResult->successful()) {
            return null;
        }

        $parsed = json_decode(trim($credResult->stdout), true);

        if (! is_array($parsed) || $parsed === []) {
            return null;
        }

        $tool->credentials = ['fields' => $parsed];
        $tool->save();

        return $this->fixResult($tool, $entry);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixAgentUser(NodeTool $tool, DriftEntry $entry): array
    {
        $this->remoteShell->run($tool->node, 'id -u agent >/dev/null 2>&1 || sudo useradd --create-home --shell /bin/bash agent', ['throw' => true]);
        $this->remoteShell->run($tool->node, 'sudo passwd -l agent >/dev/null 2>&1 || true', ['throw' => true]);

        return $this->fixResult($tool, $entry);
    }

    private function agentTldForNode(Node $node): ?string
    {
        $assignment = app(NodeRoleAssignments::class)->activeAssignment($node, NodeRoleName::Agent->value);

        if ($assignment instanceof NodeRoleAssignment) {
            $settings = $assignment->settings ?? [];
            $tld = is_array($settings) ? ($settings['tld'] ?? null) : null;

            if (is_string($tld) && trim($tld) !== '') {
                return trim($tld);
            }
        }

        if (is_string($node->tld) && trim($node->tld) !== '') {
            return trim($node->tld);
        }

        return null;
    }
}
