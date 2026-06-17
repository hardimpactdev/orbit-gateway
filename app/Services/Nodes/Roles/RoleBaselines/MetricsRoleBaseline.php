<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Operations\FleetUpdateTargetSelector;
use App\Services\Processes\ProcessServiceDefinitionRegistry;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Tools\ToolCatalog;
use Illuminate\Support\Str;
use RuntimeException;

class MetricsRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    private const string ServiceDomain = 'metrics.orbit';

    private const array HostExporterPlatforms = ['ubuntu', 'debian'];

    public function __construct(
        private readonly ProcessServiceDefinitionRegistry $serviceDefinitions,
        private readonly ProxyRouteRenderer $proxyRouteRenderer,
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
        private readonly ?FleetUpdateTargetSelector $fleetUpdateTargets = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if (! $this->nodeSupportsHostExporter($node)) {
            throw new RuntimeException('The metrics role requires an Ubuntu or Debian host.');
        }

        $this->convergeTools($node, ['docker']);
        $this->convergeProcess($node, 'prometheus', ProcessRuntime::DockerSwarm);
        $this->convergeGrafana($node);
        $this->convergeProcess($node, 'node-exporter', ProcessRuntime::Systemd);
        $this->convergeWorkloadNodeExporters($node);
        $this->syncMetricsRoute($node);
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        Process::query()
            ->where('node_id', $node->id)
            ->where('owner_type', $node->getMorphClass())
            ->where('owner_id', $node->id)
            ->whereIn('name', ['prometheus', 'grafana', 'node-exporter'])
            ->delete();

        if (! $this->hasOtherActiveMetricsRole($assignment)) {
            $this->removeWorkloadNodeExporters($node);
        }

        ProxyRoute::query()
            ->where('domain', self::ServiceDomain)
            ->where('owner_type', 'router')
            ->whereJsonContains('config->owner_name', 'grafana')
            ->delete();
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }

    private function convergeGrafana(Node $node): void
    {
        $process = $this->process($node, 'grafana');
        $password = $this->existingGrafanaPassword($process) ?? Str::random(32);
        $definition = $this->serviceDefinitions->resolve(
            definition: 'grafana',
            version: null,
            runtime: ProcessRuntime::DockerSwarm,
            node: $node,
            processName: 'grafana',
        );
        $runtimeConfig = $definition->runtimeConfig;
        $environment = is_array($runtimeConfig['environment'] ?? null) ? $runtimeConfig['environment'] : [];
        $credentials = is_array($runtimeConfig['credentials'] ?? null) ? $runtimeConfig['credentials'] : [];

        $runtimeConfig['environment'] = [
            ...$environment,
            'GF_SECURITY_ADMIN_PASSWORD' => $password,
        ];
        $runtimeConfig['credentials'] = [
            ...$credentials,
            'admin_password' => $password,
            'url' => 'https://'.self::ServiceDomain,
        ];

        $this->refreshSpecHash($runtimeConfig, ProcessRuntime::DockerSwarm, 'grafana');
        $this->persistProcess($node, 'grafana', $definition->command, ProcessRuntime::DockerSwarm, $runtimeConfig);
    }

    private function convergeProcess(Node $node, string $name, ProcessRuntime $runtime): void
    {
        $definition = $this->serviceDefinitions->resolve(
            definition: $name,
            version: null,
            runtime: $runtime,
            node: $node,
            processName: $name,
        );

        $this->persistProcess($node, $name, $definition->command, $runtime, $definition->runtimeConfig);
    }

    private function convergeWorkloadNodeExporters(Node $metricsNode): void
    {
        foreach ($this->fleetUpdateTargets()->workloadNodes() as $workloadNode) {
            if ($workloadNode->is($metricsNode)) {
                continue;
            }

            if (! $this->nodeSupportsHostExporter($workloadNode)) {
                continue;
            }

            $this->convergeProcess($workloadNode, 'node-exporter', ProcessRuntime::Systemd);
        }
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     */
    private function persistProcess(Node $node, string $name, string $command, ProcessRuntime $runtime, array $runtimeConfig): void
    {
        $process = $this->process($node, $name);

        Process::query()->updateOrCreate(
            [
                'owner_type' => $node->getMorphClass(),
                'owner_id' => $node->id,
                'name' => $name,
            ],
            [
                'node_id' => $node->id,
                'command' => $command,
                'restart_policy' => ProcessRestartPolicy::Always,
                'crash_notification' => ProcessCrashNotification::None,
                'runtime' => $runtime,
                'tool' => null,
                'runtime_config' => $runtimeConfig,
                'sort_order' => $process instanceof Process
                    ? $process->sort_order
                    : $this->nextSortOrder($node),
            ],
        );
    }

    private function process(Node $node, string $name): ?Process
    {
        return Process::query()
            ->where('owner_type', $node->getMorphClass())
            ->where('owner_id', $node->id)
            ->where('name', $name)
            ->first();
    }

    private function nextSortOrder(Node $node): int
    {
        return ((int) Process::query()
            ->where('owner_type', $node->getMorphClass())
            ->where('owner_id', $node->id)
            ->max('sort_order')) + 1;
    }

    private function removeWorkloadNodeExporters(Node $metricsNode): void
    {
        $nodeIds = $this->fleetUpdateTargets()
            ->workloadNodes()
            ->map(fn (Node $node): int => $node->id)
            ->push($metricsNode->id)
            ->unique()
            ->values()
            ->all();

        Process::query()
            ->whereIn('node_id', $nodeIds)
            ->where('owner_type', $metricsNode->getMorphClass())
            ->whereColumn('owner_id', 'node_id')
            ->where('name', 'node-exporter')
            ->delete();
    }

    private function hasOtherActiveMetricsRole(NodeRoleAssignment $assignment): bool
    {
        return NodeRoleAssignment::query()
            ->where('role', NodeRoleName::Metrics->value)
            ->where('status', NodeRoleStatus::Active->value)
            ->whereKeyNot($assignment->getKey())
            ->exists();
    }

    private function nodeSupportsHostExporter(Node $node): bool
    {
        $platform = $this->normalizedPlatform($node);

        return $platform !== null && in_array($platform, self::HostExporterPlatforms, true);
    }

    private function normalizedPlatform(Node $node): ?string
    {
        $platform = $node->platform;

        if (! is_string($platform) || trim($platform) === '') {
            return null;
        }

        return explode('_', $platform, 2)[0];
    }

    private function existingGrafanaPassword(?Process $process): ?string
    {
        if (! $process instanceof Process) {
            return null;
        }

        $runtimeConfig = is_array($process->runtime_config) ? $process->runtime_config : [];
        $credentials = is_array($runtimeConfig['credentials'] ?? null) ? $runtimeConfig['credentials'] : [];
        $password = $credentials['admin_password'] ?? null;

        return is_string($password) && $password !== '' ? $password : null;
    }

    private function syncMetricsRoute(Node $node): void
    {
        $router = $this->nodeRoleAssignments()
            ->activeRouterNodeQuery()
            ->orderBy('id')
            ->first();

        if (! $router instanceof Node) {
            return;
        }

        $backendHost = "{$node->name}.metrics.orbit";
        $config = [
            'owner_name' => 'grafana',
            'protocol' => 'http',
            'target' => [
                'type' => 'upstream',
                'value' => "http://{$backendHost}:3000",
            ],
            'upstreams' => [
                ['scheme' => 'http', 'host' => $backendHost, 'port' => 3000],
            ],
        ];
        $sourceHash = $this->proxyRouteRenderer->sourceHash(new ProxyRoute([
            'node_id' => $router->id,
            'domain' => self::ServiceDomain,
            'owner_type' => 'router',
            'kind' => 'proxy',
            'config' => $config,
        ]));

        ProxyRoute::query()->updateOrCreate(
            ['domain' => self::ServiceDomain],
            [
                'node_id' => $router->id,
                'app_id' => null,
                'workspace_id' => null,
                'owner_type' => 'router',
                'kind' => 'proxy',
                'config' => $config,
                'source_hash' => $sourceHash,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     */
    private function refreshSpecHash(array &$runtimeConfig, ProcessRuntime $runtime, string $processName): void
    {
        unset($runtimeConfig['spec_hash'], $runtimeConfig['labels']);

        $specHash = $this->specHash([
            ...$runtimeConfig,
            'runtime' => $runtime->value,
            'process' => $processName,
        ]);

        $runtimeConfig['spec_hash'] = $specHash;
        $runtimeConfig['labels'] = [
            'orbit.managed' => 'true',
            'orbit.process' => $processName,
            'orbit.process.definition' => (string) ($runtimeConfig['definition'] ?? $processName),
            'orbit.process.version_family' => (string) ($runtimeConfig['version_family'] ?? ''),
            'orbit.process.version' => (string) ($runtimeConfig['version'] ?? ''),
            'orbit.process.spec_hash' => $specHash,
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function specHash(array $spec): string
    {
        ksort($spec);

        return substr(hash('sha256', json_encode($spec, JSON_THROW_ON_ERROR)), 0, 16);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    private function fleetUpdateTargets(): FleetUpdateTargetSelector
    {
        return $this->fleetUpdateTargets ?? app(FleetUpdateTargetSelector::class);
    }
}
