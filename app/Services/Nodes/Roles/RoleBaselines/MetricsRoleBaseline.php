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
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Services\Metrics\MetricsServiceRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Operations\FleetUpdateTargetSelector;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessServiceDefinitionRegistry;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolsFixer;
use App\Services\Tools\ToolsProbe;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class MetricsRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    private const string ServiceDomain = MetricsServiceRoute::Domain;

    private const array HostExporterPlatforms = ['ubuntu', 'debian'];

    private const string PrometheusConfigPath = '/var/lib/orbit/processes/prometheus/prometheus.yml';

    private const string GrafanaDatasourcePath = '/var/lib/orbit/processes/grafana/provisioning/datasources/prometheus.yml';

    public function __construct(
        private readonly ProcessServiceDefinitionRegistry $serviceDefinitions,
        private readonly ProxyRouteRenderer $proxyRouteRenderer,
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
        private readonly ?FleetUpdateTargetSelector $fleetUpdateTargets = null,
        private readonly ?ToolsProbe $toolsProbe = null,
        private readonly ?ToolsFixer $toolsFixer = null,
        private readonly ?ProcessRuntimeDriverRegistry $processRuntimeDrivers = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if (! $this->nodeSupportsHostExporter($node)) {
            throw new RuntimeException('The metrics role requires an Ubuntu or Debian host.');
        }

        $this->convergeTools($node, ['docker', 'node-exporter']);
        $this->convergeToolRuntime($node, 'node-exporter');
        $this->convergeProcessRuntime($node, $this->convergePrometheus($node));
        $this->convergeProcessRuntime($node, $this->convergeGrafana($node));
        $this->convergeProcessRuntime($node, $this->convergeProcess($node, 'node-exporter', ProcessRuntime::Systemd));
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

        NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'node-exporter')
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

    private function convergePrometheus(Node $node): Process
    {
        $definition = $this->serviceDefinitions->resolve(
            definition: 'prometheus',
            version: null,
            runtime: ProcessRuntime::DockerSwarm,
            node: $node,
            processName: 'prometheus',
        );
        $runtimeConfig = $definition->runtimeConfig;
        $content = $this->prometheusConfig($node);

        $runtimeConfig['managed_files'] = [
            [
                'path' => self::PrometheusConfigPath,
                'content' => $content,
            ],
        ];
        $runtimeConfig['bind_mounts'] = [
            [
                'source' => self::PrometheusConfigPath,
                'target' => '/etc/prometheus/prometheus.yml',
                'read_only' => true,
            ],
        ];

        $this->refreshSpecHash($runtimeConfig, ProcessRuntime::DockerSwarm, 'prometheus');

        return $this->persistProcess($node, 'prometheus', $definition->command, ProcessRuntime::DockerSwarm, $runtimeConfig);
    }

    private function convergeGrafana(Node $node): Process
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
        $runtimeConfig['managed_files'] = [
            [
                'path' => self::GrafanaDatasourcePath,
                'content' => $this->grafanaDatasourceConfig($node),
            ],
        ];
        $runtimeConfig['bind_mounts'] = [
            [
                'source' => self::GrafanaDatasourcePath,
                'target' => '/etc/grafana/provisioning/datasources/prometheus.yml',
                'read_only' => true,
            ],
        ];

        $this->refreshSpecHash($runtimeConfig, ProcessRuntime::DockerSwarm, 'grafana');

        return $this->persistProcess($node, 'grafana', $definition->command, ProcessRuntime::DockerSwarm, $runtimeConfig);
    }

    private function convergeProcess(Node $node, string $name, ProcessRuntime $runtime): Process
    {
        $definition = $this->serviceDefinitions->resolve(
            definition: $name,
            version: null,
            runtime: $runtime,
            node: $node,
            processName: $name,
        );

        return $this->persistProcess($node, $name, $definition->command, $runtime, $definition->runtimeConfig);
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

            $this->convergeTool($workloadNode, 'node-exporter');
            $this->convergeToolRuntime($workloadNode, 'node-exporter');
            $this->convergeProcessRuntime($workloadNode, $this->convergeProcess($workloadNode, 'node-exporter', ProcessRuntime::Systemd));
        }
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     */
    private function persistProcess(Node $node, string $name, string $command, ProcessRuntime $runtime, array $runtimeConfig): Process
    {
        $process = $this->process($node, $name);

        return Process::query()->updateOrCreate(
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
                'tool' => $name === 'node-exporter' ? 'node-exporter' : null,
                'runtime_config' => $runtimeConfig,
                'sort_order' => $process instanceof Process
                    ? $process->sort_order
                    : $this->nextSortOrder($node),
            ],
        );
    }

    private function convergeToolRuntime(Node $node, string $name): void
    {
        $tool = NodeTool::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->where('name', $name)
            ->first();

        if (! $tool instanceof NodeTool) {
            return;
        }

        $snapshot = $this->toolsProbe()->introspect($tool);

        foreach ($this->toolsProbe()->diff($tool, $snapshot) as $entry) {
            $this->toolsFixer()->fix($tool, $entry);
        }
    }

    private function convergeProcessRuntime(Node $node, Process $process): void
    {
        $context = new ProcessOwnerContext(
            node: $node,
            app: null,
            workspace: null,
            owner: $node,
        );
        $runtimeApp = $context->runtimeApp();
        $workspace = $context->runtimeWorkspaceFor($process);
        $driver = $this->processRuntimeDrivers()->forProcess($process);
        $runtimeUnit = $driver->runtimeUnitName($runtimeApp, $process, $workspace);
        $preApplyScript = $this->managedFilesScript($process);

        if (! $driver->apply($node, $runtimeApp, $process, $workspace, $preApplyScript)) {
            throw new RuntimeException("Metrics process runtime unit '{$runtimeUnit}' could not be rendered.");
        }

        if (! $driver->start($node, $runtimeUnit)) {
            throw new RuntimeException("Metrics process runtime unit '{$runtimeUnit}' could not be started.");
        }
    }

    private function managedFilesScript(Process $process): ?string
    {
        $runtimeConfig = is_array($process->runtime_config) ? $process->runtime_config : [];
        $managedFiles = is_array($runtimeConfig['managed_files'] ?? null) ? $runtimeConfig['managed_files'] : [];
        $scripts = [];

        foreach ($managedFiles as $file) {
            if (! is_array($file)) {
                continue;
            }

            $path = is_string($file['path'] ?? null) ? trim($file['path']) : '';
            $content = is_string($file['content'] ?? null) ? $file['content'] : null;

            if ($path === '' || ! str_starts_with($path, '/') || $content === null) {
                continue;
            }

            $mode = is_string($file['mode'] ?? null) && preg_match('/^[0-7]{3,4}$/', $file['mode']) === 1
                ? $file['mode']
                : '0644';

            $scripts[] = sprintf(
                <<<'SH'
sudo install -d -m 0755 %s
printf %%s %s | base64 -d | sudo tee %s >/dev/null
sudo chmod %s %s
SH,
                escapeshellarg(dirname($path)),
                escapeshellarg(base64_encode($content)),
                escapeshellarg($path),
                escapeshellarg($mode),
                escapeshellarg($path),
            );
        }

        return $scripts === [] ? null : implode(PHP_EOL, $scripts);
    }

    private function prometheusConfig(Node $metricsNode): string
    {
        $blocks = $this->hostExporterNodes($metricsNode)
            ->map(function (Node $node): string {
                $target = $this->prometheusTarget($node);

                return implode(PHP_EOL, [
                    '    - targets:',
                    "        - '{$this->yamlSingleQuoted($target)}'",
                    '      labels:',
                    "        node: '{$this->yamlSingleQuoted($node->name)}'",
                ]);
            })
            ->implode(PHP_EOL);

        return rtrim(implode(PHP_EOL, [
            'global:',
            '  scrape_interval: 15s',
            '  evaluation_interval: 15s',
            '',
            'scrape_configs:',
            "  - job_name: 'orbit-node-exporter'",
            '    static_configs:',
            $blocks,
            '',
        ])).PHP_EOL;
    }

    private function grafanaDatasourceConfig(Node $metricsNode): string
    {
        $prometheusEndpoint = $this->prometheusHttpUrl($metricsNode);

        return implode(PHP_EOL, [
            'apiVersion: 1',
            'datasources:',
            '  - name: Prometheus',
            '    type: prometheus',
            '    access: proxy',
            "    url: '{$this->yamlSingleQuoted($prometheusEndpoint)}'",
            '    isDefault: true',
            '    editable: true',
            '',
        ]);
    }

    /**
     * @return Collection<int, Node>
     */
    private function hostExporterNodes(Node $metricsNode): Collection
    {
        return collect([$metricsNode])
            ->merge($this->fleetUpdateTargets()->workloadNodes())
            ->unique(fn (Node $node): int => $node->id)
            ->filter(fn (Node $node): bool => $this->nodeSupportsHostExporter($node))
            ->values();
    }

    private function prometheusTarget(Node $node): string
    {
        return $this->nodeAddress($node).':9100';
    }

    private function prometheusHttpUrl(Node $node): string
    {
        return 'http://'.$this->nodeAddress($node).':9090';
    }

    private function nodeAddress(Node $node): string
    {
        $wireGuardAddress = is_string($node->wireguard_address) ? trim($node->wireguard_address) : '';

        if ($wireGuardAddress !== '') {
            return $wireGuardAddress;
        }

        $host = is_string($node->host) ? trim($node->host) : '';

        return $host !== '' ? $host : $node->name;
    }

    private function yamlSingleQuoted(string $value): string
    {
        return str_replace("'", "''", $value);
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

        NodeTool::query()
            ->whereIn('node_id', $nodeIds)
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

    private function toolsProbe(): ToolsProbe
    {
        return $this->toolsProbe ?? app(ToolsProbe::class);
    }

    private function toolsFixer(): ToolsFixer
    {
        return $this->toolsFixer ?? app(ToolsFixer::class);
    }

    private function processRuntimeDrivers(): ProcessRuntimeDriverRegistry
    {
        return $this->processRuntimeDrivers ?? app(ProcessRuntimeDriverRegistry::class);
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

        $config = MetricsServiceRoute::config();
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
