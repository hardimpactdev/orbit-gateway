<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Services\Convergence\ManagedFile;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Firewall\FirewallRuleFixer;
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
use JsonException;
use RuntimeException;

class MetricsRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    private const string ServiceDomain = MetricsServiceRoute::Domain;

    private const array HostExporterPlatforms = ['ubuntu', 'debian'];

    private const string PrometheusConfigPath = '/var/lib/orbit/processes/prometheus/prometheus.yml';

    private const string GrafanaDatasourcePath = '/var/lib/orbit/processes/grafana/provisioning/datasources/prometheus.yml';

    private const string GrafanaDashboardProviderPath = '/var/lib/orbit/processes/grafana/provisioning/dashboards/orbit-node-resources.yml';

    private const string GrafanaNodeResourcesDashboardPath = '/var/lib/orbit/processes/grafana/dashboards/orbit-node-resources.json';

    private const string NodeExporterFirewallRuleName = 'orbit-metrics-node-exporter';

    private const string NodeExporterFirewallOwner = 'metrics';

    public function __construct(
        private readonly ProcessServiceDefinitionRegistry $serviceDefinitions,
        private readonly ProxyRouteRenderer $proxyRouteRenderer,
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
        private readonly ?FleetUpdateTargetSelector $fleetUpdateTargets = null,
        private readonly ?ToolsProbe $toolsProbe = null,
        private readonly ?ToolsFixer $toolsFixer = null,
        private readonly ?ProcessRuntimeDriverRegistry $processRuntimeDrivers = null,
        private readonly ?FirewallRuleFixer $firewallRuleFixer = null,
        private readonly ?DnsmasqReconciler $dnsmasqReconciler = null,
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
        $this->convergeNodeExporterFirewall($node, $node);
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
        } else {
            $this->removeNodeExporterFirewallRules([$node->id]);
        }

        ProxyRoute::query()
            ->where('domain', self::ServiceDomain)
            ->where('owner_type', 'router')
            ->whereJsonContains('config->owner_name', 'grafana')
            ->delete();

        $this->dnsmasqReconciler()->reconcile();
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
            [
                'path' => self::GrafanaDashboardProviderPath,
                'content' => $this->grafanaDashboardProviderConfig(),
            ],
            [
                'path' => self::GrafanaNodeResourcesDashboardPath,
                'content' => $this->grafanaNodeResourcesDashboardConfig($node),
            ],
        ];
        $runtimeConfig['bind_mounts'] = [
            [
                'source' => self::GrafanaDatasourcePath,
                'target' => '/etc/grafana/provisioning/datasources/prometheus.yml',
                'read_only' => true,
            ],
            [
                'source' => self::GrafanaDashboardProviderPath,
                'target' => '/etc/grafana/provisioning/dashboards/orbit-node-resources.yml',
                'read_only' => true,
            ],
            [
                'source' => self::GrafanaNodeResourcesDashboardPath,
                'target' => '/var/lib/grafana/dashboards/orbit-node-resources.json',
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
            $this->convergeNodeExporterFirewall($workloadNode, $metricsNode);
        }
    }

    private function convergeNodeExporterFirewall(Node $exporterNode, Node $metricsNode): void
    {
        if (! $this->nodeCanOwnNodeExporterFirewall($exporterNode, $metricsNode)) {
            return;
        }

        $source = $this->nodeAddress($metricsNode);

        if (filter_var($source, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return;
        }

        $reason = "Allow metrics node {$metricsNode->name} to scrape node-exporter.";
        $shape = [
            'direction' => 'incoming',
            'action' => 'allow',
            'source' => $source,
            'destination' => null,
            'port' => '9100',
            'protocol' => 'tcp',
        ];

        $rule = FirewallRule::query()->updateOrCreate(
            [
                'node_id' => $exporterNode->id,
                'name' => self::NodeExporterFirewallRuleName,
            ],
            [
                ...$shape,
                'reason' => $reason,
                'source_hash' => $this->firewallSourceHash($exporterNode->name, self::NodeExporterFirewallRuleName, $shape, $reason),
                'address_family' => 'v4',
                'interface' => 'wireguard',
                'owner' => self::NodeExporterFirewallOwner,
                'protected' => true,
            ],
        );

        try {
            $this->firewallRuleFixer()->fix($rule->refresh(), new DriftEntry(
                family: 'firewall_rule',
                key: 'firewall_rule.rule_missing',
                kind: DriftKind::Missing,
                summary: "Apply metrics node-exporter firewall rule {$rule->name}.",
            ));
        } catch (\Throwable $exception) {
            if ($this->shouldDeferFirewallBackendMutation()) {
                return;
            }

            throw new RuntimeException("Metrics node-exporter firewall rule '{$rule->name}' could not be applied.", previous: $exception);
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

            $scripts[] = new ManagedFile(
                path: $path,
                content: $content,
                mode: $mode,
            )->writeScript();
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
            'deleteDatasources:',
            '  - name: Prometheus',
            '    orgId: 1',
            'prune: true',
            'datasources:',
            '  - name: Prometheus',
            '    uid: orbit-prometheus',
            '    orgId: 1',
            '    type: prometheus',
            '    access: proxy',
            "    url: '{$this->yamlSingleQuoted($prometheusEndpoint)}'",
            '    isDefault: true',
            '    editable: true',
            '    version: 1',
            '',
        ]);
    }

    private function grafanaDashboardProviderConfig(): string
    {
        return implode(PHP_EOL, [
            'apiVersion: 1',
            'providers:',
            '  - name: Orbit node resources',
            '    orgId: 1',
            '    folder: Orbit',
            '    folderUid: orbit',
            '    type: file',
            '    disableDeletion: false',
            '    updateIntervalSeconds: 30',
            '    allowUiUpdates: true',
            '    options:',
            '      path: /var/lib/grafana/dashboards',
            '      foldersFromFilesStructure: false',
            '',
        ]);
    }

    private function grafanaNodeResourcesDashboardConfig(Node $metricsNode): string
    {
        try {
            $content = json_encode([
                'annotations' => [
                    'list' => [
                        [
                            'builtIn' => 1,
                            'datasource' => [
                                'type' => 'grafana',
                                'uid' => '-- Grafana --',
                            ],
                            'enable' => true,
                            'hide' => true,
                            'iconColor' => 'rgba(0, 211, 255, 1)',
                            'name' => 'Annotations & Alerts',
                            'type' => 'dashboard',
                        ],
                    ],
                ],
                'editable' => true,
                'fiscalYearStartMonth' => 0,
                'graphTooltip' => 0,
                'id' => null,
                'links' => [],
                'liveNow' => false,
                'panels' => [
                    [
                        'datasource' => [
                            'type' => 'prometheus',
                            'uid' => 'orbit-prometheus',
                        ],
                        'fieldConfig' => [
                            'defaults' => [
                                'mappings' => [],
                                'thresholds' => [
                                    'mode' => 'absolute',
                                    'steps' => [
                                        ['color' => 'green', 'value' => null],
                                        ['color' => 'red', 'value' => 1],
                                    ],
                                ],
                                'unit' => 'short',
                            ],
                            'overrides' => [],
                        ],
                        'gridPos' => ['h' => 4, 'w' => 4, 'x' => 0, 'y' => 0],
                        'id' => 1,
                        'options' => [
                            'colorMode' => 'value',
                            'graphMode' => 'area',
                            'justifyMode' => 'auto',
                            'orientation' => 'auto',
                            'reduceOptions' => [
                                'calcs' => ['lastNotNull'],
                                'fields' => '',
                                'values' => false,
                            ],
                            'textMode' => 'auto',
                        ],
                        'targets' => [
                            $this->grafanaPrometheusTarget('A', 'up{job="orbit-node-exporter",node="$node"}'),
                        ],
                        'title' => 'Exporter Up',
                        'type' => 'stat',
                    ],
                    [
                        'datasource' => [
                            'type' => 'prometheus',
                            'uid' => 'orbit-prometheus',
                        ],
                        'fieldConfig' => [
                            'defaults' => [
                                'mappings' => [],
                                'max' => 100,
                                'min' => 0,
                                'thresholds' => [
                                    'mode' => 'percentage',
                                    'steps' => [
                                        ['color' => 'green', 'value' => null],
                                        ['color' => 'orange', 'value' => 70],
                                        ['color' => 'red', 'value' => 90],
                                    ],
                                ],
                                'unit' => 'percent',
                            ],
                            'overrides' => [],
                        ],
                        'gridPos' => ['h' => 4, 'w' => 5, 'x' => 4, 'y' => 0],
                        'id' => 2,
                        'options' => [
                            'colorMode' => 'value',
                            'graphMode' => 'area',
                            'justifyMode' => 'auto',
                            'orientation' => 'auto',
                            'reduceOptions' => [
                                'calcs' => ['lastNotNull'],
                                'fields' => '',
                                'values' => false,
                            ],
                            'textMode' => 'auto',
                        ],
                        'targets' => [
                            $this->grafanaPrometheusTarget(
                                'A',
                                '100 - (avg by (node) (rate(node_cpu_seconds_total{job="orbit-node-exporter",mode="idle",node="$node"}[5m])) * 100)',
                            ),
                        ],
                        'title' => 'CPU Used',
                        'type' => 'stat',
                    ],
                    [
                        'datasource' => [
                            'type' => 'prometheus',
                            'uid' => 'orbit-prometheus',
                        ],
                        'fieldConfig' => [
                            'defaults' => [
                                'mappings' => [],
                                'max' => 100,
                                'min' => 0,
                                'thresholds' => [
                                    'mode' => 'percentage',
                                    'steps' => [
                                        ['color' => 'green', 'value' => null],
                                        ['color' => 'orange', 'value' => 75],
                                        ['color' => 'red', 'value' => 90],
                                    ],
                                ],
                                'unit' => 'percent',
                            ],
                            'overrides' => [],
                        ],
                        'gridPos' => ['h' => 4, 'w' => 5, 'x' => 9, 'y' => 0],
                        'id' => 3,
                        'options' => [
                            'colorMode' => 'value',
                            'graphMode' => 'area',
                            'justifyMode' => 'auto',
                            'orientation' => 'auto',
                            'reduceOptions' => [
                                'calcs' => ['lastNotNull'],
                                'fields' => '',
                                'values' => false,
                            ],
                            'textMode' => 'auto',
                        ],
                        'targets' => [
                            $this->grafanaPrometheusTarget(
                                'A',
                                '100 * (1 - (node_memory_MemAvailable_bytes{job="orbit-node-exporter",node="$node"} / node_memory_MemTotal_bytes{job="orbit-node-exporter",node="$node"}))',
                            ),
                        ],
                        'title' => 'Memory Used',
                        'type' => 'stat',
                    ],
                    [
                        'datasource' => [
                            'type' => 'prometheus',
                            'uid' => 'orbit-prometheus',
                        ],
                        'fieldConfig' => [
                            'defaults' => [
                                'mappings' => [],
                                'max' => 100,
                                'min' => 0,
                                'thresholds' => [
                                    'mode' => 'percentage',
                                    'steps' => [
                                        ['color' => 'green', 'value' => null],
                                        ['color' => 'orange', 'value' => 80],
                                        ['color' => 'red', 'value' => 95],
                                    ],
                                ],
                                'unit' => 'percent',
                            ],
                            'overrides' => [],
                        ],
                        'gridPos' => ['h' => 4, 'w' => 5, 'x' => 14, 'y' => 0],
                        'id' => 4,
                        'options' => [
                            'colorMode' => 'value',
                            'graphMode' => 'area',
                            'justifyMode' => 'auto',
                            'orientation' => 'auto',
                            'reduceOptions' => [
                                'calcs' => ['lastNotNull'],
                                'fields' => '',
                                'values' => false,
                            ],
                            'textMode' => 'auto',
                        ],
                        'targets' => [
                            $this->grafanaPrometheusTarget(
                                'A',
                                '100 * (1 - (node_filesystem_avail_bytes{job="orbit-node-exporter",node="$node",mountpoint="/",fstype!~"tmpfs|overlay|squashfs"} / node_filesystem_size_bytes{job="orbit-node-exporter",node="$node",mountpoint="/",fstype!~"tmpfs|overlay|squashfs"}))',
                            ),
                        ],
                        'title' => 'Root Disk Used',
                        'type' => 'stat',
                    ],
                    [
                        'datasource' => [
                            'type' => 'prometheus',
                            'uid' => 'orbit-prometheus',
                        ],
                        'fieldConfig' => [
                            'defaults' => [
                                'custom' => [
                                    'drawStyle' => 'line',
                                    'fillOpacity' => 10,
                                    'lineInterpolation' => 'linear',
                                    'lineWidth' => 1,
                                    'pointSize' => 5,
                                    'showPoints' => 'never',
                                    'spanNulls' => false,
                                ],
                                'mappings' => [],
                                'thresholds' => [
                                    'mode' => 'absolute',
                                    'steps' => [
                                        ['color' => 'green', 'value' => null],
                                    ],
                                ],
                                'unit' => 'percent',
                            ],
                            'overrides' => [],
                        ],
                        'gridPos' => ['h' => 8, 'w' => 12, 'x' => 0, 'y' => 4],
                        'id' => 5,
                        'options' => [
                            'legend' => [
                                'calcs' => ['lastNotNull'],
                                'displayMode' => 'list',
                                'placement' => 'bottom',
                            ],
                            'tooltip' => [
                                'mode' => 'single',
                                'sort' => 'none',
                            ],
                        ],
                        'targets' => [
                            $this->grafanaPrometheusTarget(
                                'A',
                                '100 - (avg by (mode) (rate(node_cpu_seconds_total{job="orbit-node-exporter",node="$node",mode!="idle"}[5m])) * 100)',
                                '{{mode}}',
                            ),
                        ],
                        'title' => 'CPU By Mode',
                        'type' => 'timeseries',
                    ],
                    [
                        'datasource' => [
                            'type' => 'prometheus',
                            'uid' => 'orbit-prometheus',
                        ],
                        'fieldConfig' => [
                            'defaults' => [
                                'custom' => [
                                    'drawStyle' => 'line',
                                    'fillOpacity' => 10,
                                    'lineInterpolation' => 'linear',
                                    'lineWidth' => 1,
                                    'pointSize' => 5,
                                    'showPoints' => 'never',
                                    'spanNulls' => false,
                                ],
                                'mappings' => [],
                                'thresholds' => [
                                    'mode' => 'absolute',
                                    'steps' => [
                                        ['color' => 'green', 'value' => null],
                                    ],
                                ],
                                'unit' => 'short',
                            ],
                            'overrides' => [],
                        ],
                        'gridPos' => ['h' => 8, 'w' => 12, 'x' => 12, 'y' => 4],
                        'id' => 6,
                        'options' => [
                            'legend' => [
                                'calcs' => ['lastNotNull'],
                                'displayMode' => 'list',
                                'placement' => 'bottom',
                            ],
                            'tooltip' => [
                                'mode' => 'single',
                                'sort' => 'none',
                            ],
                        ],
                        'targets' => [
                            $this->grafanaPrometheusTarget('A', 'node_load1{job="orbit-node-exporter",node="$node"}', 'load1'),
                            $this->grafanaPrometheusTarget('B', 'node_load5{job="orbit-node-exporter",node="$node"}', 'load5'),
                            $this->grafanaPrometheusTarget('C', 'node_load15{job="orbit-node-exporter",node="$node"}', 'load15'),
                        ],
                        'title' => 'Load Average',
                        'type' => 'timeseries',
                    ],
                    [
                        'datasource' => [
                            'type' => 'prometheus',
                            'uid' => 'orbit-prometheus',
                        ],
                        'fieldConfig' => [
                            'defaults' => [
                                'custom' => [
                                    'drawStyle' => 'line',
                                    'fillOpacity' => 10,
                                    'lineInterpolation' => 'linear',
                                    'lineWidth' => 1,
                                    'pointSize' => 5,
                                    'showPoints' => 'never',
                                    'spanNulls' => false,
                                ],
                                'mappings' => [],
                                'thresholds' => [
                                    'mode' => 'absolute',
                                    'steps' => [
                                        ['color' => 'green', 'value' => null],
                                    ],
                                ],
                                'unit' => 'decbytes',
                            ],
                            'overrides' => [],
                        ],
                        'gridPos' => ['h' => 8, 'w' => 12, 'x' => 0, 'y' => 12],
                        'id' => 7,
                        'options' => [
                            'legend' => [
                                'calcs' => ['lastNotNull'],
                                'displayMode' => 'list',
                                'placement' => 'bottom',
                            ],
                            'tooltip' => [
                                'mode' => 'single',
                                'sort' => 'none',
                            ],
                        ],
                        'targets' => [
                            $this->grafanaPrometheusTarget('A', 'node_memory_MemTotal_bytes{job="orbit-node-exporter",node="$node"} - node_memory_MemAvailable_bytes{job="orbit-node-exporter",node="$node"}', 'used'),
                            $this->grafanaPrometheusTarget('B', 'node_memory_MemAvailable_bytes{job="orbit-node-exporter",node="$node"}', 'available'),
                        ],
                        'title' => 'Memory',
                        'type' => 'timeseries',
                    ],
                    [
                        'datasource' => [
                            'type' => 'prometheus',
                            'uid' => 'orbit-prometheus',
                        ],
                        'fieldConfig' => [
                            'defaults' => [
                                'custom' => [
                                    'drawStyle' => 'line',
                                    'fillOpacity' => 10,
                                    'lineInterpolation' => 'linear',
                                    'lineWidth' => 1,
                                    'pointSize' => 5,
                                    'showPoints' => 'never',
                                    'spanNulls' => false,
                                ],
                                'mappings' => [],
                                'thresholds' => [
                                    'mode' => 'absolute',
                                    'steps' => [
                                        ['color' => 'green', 'value' => null],
                                    ],
                                ],
                                'unit' => 'Bps',
                            ],
                            'overrides' => [],
                        ],
                        'gridPos' => ['h' => 8, 'w' => 12, 'x' => 12, 'y' => 12],
                        'id' => 8,
                        'options' => [
                            'legend' => [
                                'calcs' => ['lastNotNull'],
                                'displayMode' => 'list',
                                'placement' => 'bottom',
                            ],
                            'tooltip' => [
                                'mode' => 'single',
                                'sort' => 'none',
                            ],
                        ],
                        'targets' => [
                            $this->grafanaPrometheusTarget(
                                'A',
                                'sum by (node) (rate(node_network_receive_bytes_total{job="orbit-node-exporter",node="$node",device!~"lo|docker.*|br-.*|veth.*"}[5m]))',
                                'receive',
                            ),
                            $this->grafanaPrometheusTarget(
                                'B',
                                'sum by (node) (rate(node_network_transmit_bytes_total{job="orbit-node-exporter",node="$node",device!~"lo|docker.*|br-.*|veth.*"}[5m]))',
                                'transmit',
                            ),
                        ],
                        'title' => 'Network Throughput',
                        'type' => 'timeseries',
                    ],
                ],
                'refresh' => '30s',
                'schemaVersion' => 39,
                'style' => 'dark',
                'tags' => ['orbit', 'node-exporter'],
                'templating' => [
                    'list' => [
                        [
                            'current' => [
                                'selected' => true,
                                'text' => $metricsNode->name,
                                'value' => $metricsNode->name,
                            ],
                            'datasource' => [
                                'type' => 'prometheus',
                                'uid' => 'orbit-prometheus',
                            ],
                            'definition' => 'label_values(up{job="orbit-node-exporter"}, node)',
                            'hide' => 0,
                            'includeAll' => false,
                            'label' => 'Node',
                            'multi' => false,
                            'name' => 'node',
                            'options' => $this->grafanaNodeVariableOptions($metricsNode),
                            'query' => 'label_values(up{job="orbit-node-exporter"}, node)',
                            'refresh' => 1,
                            'regex' => '',
                            'sort' => 1,
                            'type' => 'query',
                        ],
                    ],
                ],
                'time' => [
                    'from' => 'now-1h',
                    'to' => 'now',
                ],
                'timepicker' => [],
                'timezone' => 'browser',
                'title' => 'Orbit Node Resources',
                'uid' => 'orbit-node-resources',
                'version' => 1,
                'weekStart' => '',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The Orbit node resources Grafana dashboard could not be encoded.', previous: $exception);
        }

        return "{$content}\n";
    }

    /**
     * @return list<array{selected: bool, text: string, value: string}>
     */
    private function grafanaNodeVariableOptions(Node $metricsNode): array
    {
        return $this->hostExporterNodes($metricsNode)
            ->map(fn (Node $node): array => [
                'selected' => $node->is($metricsNode),
                'text' => $node->name,
                'value' => $node->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function grafanaPrometheusTarget(string $refId, string $expr, string $legendFormat = ''): array
    {
        return [
            'datasource' => [
                'type' => 'prometheus',
                'uid' => 'orbit-prometheus',
            ],
            'editorMode' => 'code',
            'expr' => $expr,
            'instant' => false,
            'legendFormat' => $legendFormat,
            'range' => true,
            'refId' => $refId,
        ];
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

        $this->removeNodeExporterFirewallRules($nodeIds);
    }

    /**
     * @param  list<int>  $nodeIds
     */
    private function removeNodeExporterFirewallRules(array $nodeIds): void
    {
        $rules = FirewallRule::query()
            ->with('node')
            ->whereIn('node_id', $nodeIds)
            ->where('name', self::NodeExporterFirewallRuleName)
            ->where('owner', self::NodeExporterFirewallOwner)
            ->get();

        foreach ($rules as $rule) {
            try {
                $this->firewallRuleFixer()->remove($rule);
            } catch (\Throwable $exception) {
                if (! $this->shouldDeferFirewallBackendMutation()) {
                    throw new RuntimeException("Metrics node-exporter firewall rule '{$rule->name}' could not be removed.", previous: $exception);
                }
            }

            $rule->delete();
        }
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

    private function firewallRuleFixer(): FirewallRuleFixer
    {
        return $this->firewallRuleFixer ?? app(FirewallRuleFixer::class);
    }

    private function nodeSupportsHostExporter(Node $node): bool
    {
        $platform = $this->normalizedPlatform($node);

        return $platform !== null && in_array($platform, self::HostExporterPlatforms, true);
    }

    private function nodeCanOwnNodeExporterFirewall(Node $exporterNode, Node $metricsNode): bool
    {
        if (! $exporterNode->isActive() || ! $this->isUbuntuPlatform($exporterNode)) {
            return false;
        }

        if ($exporterNode->is($metricsNode)) {
            return true;
        }

        return $this->nodeRoleAssignments()->nodeCanOwnFirewallRules($exporterNode);
    }

    private function isUbuntuPlatform(Node $node): bool
    {
        return $node->platform === 'ubuntu' || str_starts_with((string) $node->platform, 'ubuntu_');
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

        $this->dnsmasqReconciler()->reconcile();
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

    private function dnsmasqReconciler(): DnsmasqReconciler
    {
        return $this->dnsmasqReconciler ?? app(DnsmasqReconciler::class);
    }

    /**
     * @param  array<string, string|null>  $shape
     */
    private function firewallSourceHash(string $node, string $name, array $shape, ?string $reason): string
    {
        return hash('sha256', json_encode([
            'node' => $node,
            'name' => $name,
            'shape' => $shape,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));
    }

    private function shouldDeferFirewallBackendMutation(): bool
    {
        $provider = $this->e2eEnvironmentValue('ORBIT_E2E_TOPOLOGY_PROVIDER');

        if ($provider !== null) {
            return strtolower(trim($provider)) === 'docker';
        }

        $providers = $this->e2eEnvironmentValue('ORBIT_E2E_TOPOLOGY_PROVIDERS');

        if ($providers === null) {
            return false;
        }

        return in_array('docker', array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', $providers),
        ), true);
    }

    private function e2eEnvironmentValue(string $key): ?string
    {
        $processValue = getenv($key);

        if (is_string($processValue) && $processValue !== '') {
            return $processValue;
        }

        $serverValue = $_SERVER[$key] ?? null;

        if (is_string($serverValue) && $serverValue !== '') {
            return $serverValue;
        }

        $envValue = $_ENV[$key] ?? null;

        return is_string($envValue) && $envValue !== '' ? $envValue : null;
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
