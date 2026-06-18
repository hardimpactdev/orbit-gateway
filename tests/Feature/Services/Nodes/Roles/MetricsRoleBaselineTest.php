<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Enums\Processes\ProcessRuntime;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->metricsShell = new MetricsRoleBaselineRecordingShell;
    $this->metricsDnsmasqReconciler = new MetricsRoleBaselineRecordingDnsmasqReconciler;

    app()->instance(RemoteShell::class, $this->metricsShell);
    app()->instance(DnsmasqReconciler::class, $this->metricsDnsmasqReconciler);
});

it('converges metrics role intent as process-owned Prometheus Grafana and host exporter services', function (): void {
    $router = Node::factory()->create([
        'name' => 'router-1',
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.1',
        'status' => NodeStatus::Active,
    ]);
    NodeRoleAssignment::factory()->for($router)->create([
        'role' => 'router',
        'status' => NodeRoleStatus::Active,
    ]);

    $node = Node::factory()->create([
        'name' => 'metrics-1',
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.55',
        'status' => NodeStatus::Active,
    ]);
    $assignment = NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'metrics',
        'status' => NodeRoleStatus::Pending,
    ]);

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    $tools = NodeTool::query()
        ->where('node_id', $node->id)
        ->pluck('expected_state', 'name')
        ->all();

    expect($tools)->toMatchArray([
        'docker' => 'installed',
        'node-exporter' => 'installed',
    ]);

    $processes = Process::query()
        ->where('node_id', $node->id)
        ->orderBy('name')
        ->get()
        ->keyBy('name');

    $grafanaConfig = $processes['grafana']->runtime_config;
    $grafanaManagedFiles = collect($grafanaConfig['managed_files'])->keyBy('path');
    $grafanaBindMounts = collect($grafanaConfig['bind_mounts'])->keyBy('target');

    expect($processes->keys()->all())->toBe(['grafana', 'node-exporter', 'prometheus'])
        ->and($processes['grafana']->owner_type)->toBe($node->getMorphClass())
        ->and($processes['grafana']->runtime)->toBe(ProcessRuntime::DockerSwarm)
        ->and($grafanaConfig['definition'])->toBe('grafana')
        ->and($grafanaConfig['endpoint']['port'])->toBe(3000)
        ->and($grafanaConfig['environment']['GF_SECURITY_ADMIN_PASSWORD'])->toBeString()
        ->and($grafanaConfig['credentials']['admin_password'])->toBe(
            $grafanaConfig['environment']['GF_SECURITY_ADMIN_PASSWORD'],
        )
        ->and($grafanaManagedFiles->keys()->all())->toContain(
            '/var/lib/orbit/processes/grafana/provisioning/datasources/prometheus.yml',
            '/var/lib/orbit/processes/grafana/provisioning/dashboards/orbit-node-resources.yml',
            '/var/lib/orbit/processes/grafana/dashboards/orbit-node-resources.json',
        )
        ->and($grafanaManagedFiles['/var/lib/orbit/processes/grafana/provisioning/datasources/prometheus.yml']['content'])->toContain(
            'deleteDatasources:',
            '    orgId: 1',
            'prune: true',
            '    uid: orbit-prometheus',
            '    orgId: 1',
            "url: 'http://10.6.0.55:9090'",
            '    version: 1',
        )
        ->and($grafanaManagedFiles['/var/lib/orbit/processes/grafana/provisioning/dashboards/orbit-node-resources.yml']['content'])->toContain(
            'path: /var/lib/grafana/dashboards',
        )
        ->and($grafanaBindMounts['/etc/grafana/provisioning/datasources/prometheus.yml'])->toMatchArray([
            'source' => '/var/lib/orbit/processes/grafana/provisioning/datasources/prometheus.yml',
            'target' => '/etc/grafana/provisioning/datasources/prometheus.yml',
            'read_only' => true,
        ])
        ->and($grafanaBindMounts['/etc/grafana/provisioning/dashboards/orbit-node-resources.yml'])->toMatchArray([
            'source' => '/var/lib/orbit/processes/grafana/provisioning/dashboards/orbit-node-resources.yml',
            'target' => '/etc/grafana/provisioning/dashboards/orbit-node-resources.yml',
            'read_only' => true,
        ])
        ->and($grafanaBindMounts['/var/lib/grafana/dashboards/orbit-node-resources.json'])->toMatchArray([
            'source' => '/var/lib/orbit/processes/grafana/dashboards/orbit-node-resources.json',
            'target' => '/var/lib/grafana/dashboards/orbit-node-resources.json',
            'read_only' => true,
        ])
        ->and($processes['prometheus']->runtime)->toBe(ProcessRuntime::DockerSwarm)
        ->and($processes['prometheus']->runtime_config['definition'])->toBe('prometheus')
        ->and($processes['prometheus']->runtime_config['endpoint']['port'])->toBe(9090)
        ->and($processes['prometheus']->runtime_config['managed_files'][0]['content'])->toContain("'10.6.0.55:9100'")
        ->and($processes['prometheus']->runtime_config['bind_mounts'][0])->toMatchArray([
            'source' => '/var/lib/orbit/processes/prometheus/prometheus.yml',
            'target' => '/etc/prometheus/prometheus.yml',
            'read_only' => true,
        ])
        ->and($processes['node-exporter']->runtime)->toBe(ProcessRuntime::Systemd)
        ->and($processes['node-exporter']->tool)->toBe('node-exporter')
        ->and($processes['node-exporter']->runtime_config['definition'])->toBe('node-exporter')
        ->and($processes['node-exporter']->runtime_config['endpoint']['port'])->toBe(9100);

    $grafanaDashboard = json_decode(
        $grafanaManagedFiles['/var/lib/orbit/processes/grafana/dashboards/orbit-node-resources.json']['content'],
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $grafanaNodeVariable = collect($grafanaDashboard['templating']['list'])->firstWhere('name', 'node');
    $grafanaPanelExpressions = collect($grafanaDashboard['panels'])
        ->flatMap(fn (array $panel): array => collect($panel['targets'] ?? [])->pluck('expr')->all())
        ->all();

    expect($grafanaDashboard['title'])->toBe('Orbit Node Resources')
        ->and($grafanaNodeVariable)->toMatchArray([
            'label' => 'Node',
            'query' => 'label_values(up{job="orbit-node-exporter"}, node)',
            'current' => [
                'selected' => true,
                'text' => 'metrics-1',
                'value' => 'metrics-1',
            ],
        ])
        ->and($grafanaPanelExpressions)->toContain(
            'up{job="orbit-node-exporter",node="$node"}',
            '100 * (1 - (node_memory_MemAvailable_bytes{job="orbit-node-exporter",node="$node"} / node_memory_MemTotal_bytes{job="orbit-node-exporter",node="$node"}))',
            'sum by (node) (rate(node_network_receive_bytes_total{job="orbit-node-exporter",node="$node",device!~"lo|docker.*|br-.*|veth.*"}[5m]))',
        );

    $scripts = $this->metricsShell->scripts();

    expect($scripts)
        ->toContain('node_exporter_url=')
        ->toContain('/var/lib/orbit/processes/prometheus/prometheus.yml')
        ->toContain('/var/lib/orbit/processes/grafana/provisioning/datasources/prometheus.yml')
        ->toContain('/var/lib/orbit/processes/grafana/provisioning/dashboards/orbit-node-resources.yml')
        ->toContain('/var/lib/orbit/processes/grafana/dashboards/orbit-node-resources.json')
        ->toContain("docker service update --detach --replicas 1 'orbit-prometheus'")
        ->toContain("docker service update --detach --replicas 1 'orbit-grafana'")
        ->toContain("sudo systemctl start 'node-exporter.service'");

    $route = ProxyRoute::query()->where('domain', 'metrics.orbit')->sole();

    expect($route->node_id)->toBe($router->id)
        ->and($route->owner_type)->toBe('router')
        ->and($route->kind)->toBe('proxy')
        ->and($route->config)->toMatchArray([
            'owner_name' => 'grafana',
            'protocol' => 'http',
            'target' => [
                'type' => 'upstream',
                'value' => 'http://host.docker.internal:3000',
            ],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'host.docker.internal', 'port' => 3000],
            ],
        ])
        ->and($this->metricsDnsmasqReconciler->reconciles)->toBe(1);
});

it('rewrites stale metrics service route intent when the metrics baseline reconverges', function (): void {
    $node = Node::factory()->create([
        'name' => 'gateway',
        'platform' => 'debian_12',
        'wireguard_address' => '10.6.0.1',
        'status' => NodeStatus::Active,
    ]);
    NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'router',
        'status' => NodeRoleStatus::Active,
    ]);
    $assignment = NodeRoleAssignment::factory()->for($node)->create([
        'role' => 'metrics',
        'status' => NodeRoleStatus::Pending,
    ]);

    ProxyRoute::factory()->create([
        'node_id' => $node->id,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => [
            'owner_name' => 'grafana',
            'protocol' => 'http',
            'target' => [
                'type' => 'upstream',
                'value' => 'http://gateway.metrics.orbit:3000',
            ],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'gateway.metrics.orbit', 'port' => 3000],
            ],
        ],
    ]);

    app(NodeRoleBaselineConverger::class)->converge($node, $assignment);

    $route = ProxyRoute::query()->where('domain', 'metrics.orbit')->sole();

    expect($route->config['target']['value'])->toBe('http://host.docker.internal:3000')
        ->and($route->config['upstreams'][0])->toBe([
            'scheme' => 'http',
            'host' => 'host.docker.internal',
            'port' => 3000,
        ]);
});

it('adds the metrics role through the role assignment service', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.44',
        'status' => NodeStatus::Active,
    ]);

    $assignment = app(NodeRoleAssignmentService::class)->add($node, 'metrics', []);

    expect($assignment->status)->toBe(NodeRoleStatus::Active)
        ->and(Process::query()
            ->where('node_id', $node->id)
            ->whereIn('name', ['grafana', 'node-exporter', 'prometheus'])
            ->count())->toBe(3);
});

it('adds the metrics role to the debian gateway node', function (): void {
    $node = Node::factory()->gateway()->create([
        'name' => 'gateway',
        'platform' => 'debian_12',
        'wireguard_address' => '10.6.0.1',
        'status' => NodeStatus::Active,
    ]);

    $assignment = app(NodeRoleAssignmentService::class)->add($node, 'metrics', []);

    expect($assignment->status)->toBe(NodeRoleStatus::Active)
        ->and(Process::query()
            ->where('node_id', $node->id)
            ->whereIn('name', ['grafana', 'node-exporter', 'prometheus'])
            ->count())->toBe(3);
});

it('renders metrics node processes after syncing role-derived node fields', function (): void {
    $node = Node::factory()->gateway()->create([
        'name' => 'gateway',
        'platform' => 'debian_12',
        'wireguard_address' => '10.6.0.1',
        'status' => NodeStatus::Active,
        'tld' => 'gateway',
    ]);

    app(NodeRoleAssignmentService::class)->add($node, 'metrics', []);

    expect($node->refresh()->tld)->toBeNull()
        ->and($this->metricsShell->scriptsForNode('gateway'))
        ->toContain('Environment="APP_URL=https://gateway"')
        ->not->toContain('https://gateway.gateway');
});

it('converges node exporter process intent for active workload nodes', function (): void {
    $gateway = Node::factory()->gateway()->create([
        'name' => 'gateway',
        'platform' => 'debian_12',
        'wireguard_address' => '10.6.0.1',
        'status' => NodeStatus::Active,
    ]);
    $assignment = NodeRoleAssignment::factory()->for($gateway)->create([
        'role' => 'metrics',
        'status' => NodeRoleStatus::Pending,
    ]);

    Node::factory()->agent()->create([
        'name' => 'agent-1',
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.10',
        'status' => NodeStatus::Active,
    ]);
    Node::factory()->appDev()->create([
        'name' => 'app-1',
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.11',
        'status' => NodeStatus::Active,
    ]);
    Node::factory()->appProd()->create([
        'name' => 'main-1',
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.12',
        'status' => NodeStatus::Active,
    ]);
    Node::factory()->database()->create([
        'name' => 'database-1',
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.13',
        'status' => NodeStatus::Active,
    ]);
    Node::factory()->ingress()->create([
        'name' => 'ingress-1',
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.14',
        'status' => NodeStatus::Active,
    ]);
    Node::factory()->database()->create([
        'name' => 'inactive-1',
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.15',
        'status' => NodeStatus::Inactive,
    ]);
    Node::factory()->create([
        'name' => 'client-1',
        'platform' => 'darwin',
        'wireguard_address' => '10.6.0.16',
        'status' => NodeStatus::Active,
    ]);

    app(NodeRoleBaselineConverger::class)->converge($gateway, $assignment);

    $exporterNodes = Process::query()
        ->where('name', 'node-exporter')
        ->with('node')
        ->get()
        ->map(fn (Process $process): string => $process->node->name)
        ->sort()
        ->values()
        ->all();

    expect($exporterNodes)->toBe([
        'agent-1',
        'app-1',
        'database-1',
        'gateway',
        'ingress-1',
        'main-1',
    ]);

    $exporterToolNodes = NodeTool::query()
        ->where('name', 'node-exporter')
        ->with('node')
        ->get()
        ->map(fn (NodeTool $tool): string => $tool->node->name)
        ->sort()
        ->values()
        ->all();

    expect($exporterToolNodes)->toBe($exporterNodes);

    $firewallRuleNodes = FirewallRule::query()
        ->where('name', 'orbit-metrics-node-exporter')
        ->where('owner', 'metrics')
        ->with('node')
        ->get()
        ->mapWithKeys(fn (FirewallRule $rule): array => [$rule->node->name => $rule])
        ->sortKeys();

    expect($firewallRuleNodes->keys()->all())->toBe([
        'agent-1',
        'app-1',
        'database-1',
        'ingress-1',
        'main-1',
    ]);

    $firewallRuleNodes->each(function (FirewallRule $rule) use ($gateway): void {
        expect($rule->direction)->toBe('incoming')
            ->and($rule->action)->toBe('allow')
            ->and($rule->source)->toBe($gateway->wireguard_address)
            ->and($rule->destination)->toBeNull()
            ->and($rule->port)->toBe('9100')
            ->and($rule->protocol)->toBe('tcp')
            ->and($rule->address_family)->toBe('v4')
            ->and($rule->interface)->toBe('wireguard')
            ->and($rule->protected)->toBeTrue()
            ->and($rule->reason)->toBe('Allow metrics node gateway to scrape node-exporter.');
    });

    $prometheus = Process::query()
        ->where('node_id', $gateway->id)
        ->where('name', 'prometheus')
        ->sole();
    $config = $prometheus->runtime_config['managed_files'][0]['content'];

    expect($config)
        ->toContain("'10.6.0.1:9100'")
        ->toContain("'10.6.0.10:9100'")
        ->toContain("'10.6.0.11:9100'")
        ->toContain("'10.6.0.12:9100'")
        ->toContain("'10.6.0.13:9100'")
        ->toContain("'10.6.0.14:9100'")
        ->not->toContain('10.6.0.15:9100')
        ->not->toContain('10.6.0.16:9100');

    expect($this->metricsShell->scriptsForNode('agent-1'))
        ->toContain("sudo systemctl start 'node-exporter.service'")
        ->toContain('sudo ufw allow in on $(ip -o link show type wireguard')
        ->toContain("from '10.6.0.1' to 0.0.0.0/0 port '9100' proto 'tcp'");
});

it('removes workload node exporter process intent when the last metrics role is removed', function (): void {
    $gateway = Node::factory()->gateway()->create([
        'name' => 'gateway',
        'platform' => 'debian_12',
        'wireguard_address' => '10.6.0.1',
        'status' => NodeStatus::Active,
    ]);
    $assignment = NodeRoleAssignment::factory()->for($gateway)->create([
        'role' => 'metrics',
        'status' => NodeRoleStatus::Active,
    ]);
    $workload = Node::factory()->appDev()->create([
        'name' => 'app-1',
        'platform' => 'ubuntu',
        'wireguard_address' => '10.6.0.11',
        'status' => NodeStatus::Active,
    ]);

    app(NodeRoleBaselineConverger::class)->converge($gateway, $assignment);

    expect(Process::query()
        ->where('name', 'node-exporter')
        ->whereIn('node_id', [$gateway->id, $workload->id])
        ->count())->toBe(2)
        ->and(NodeTool::query()
            ->where('name', 'node-exporter')
            ->whereIn('node_id', [$gateway->id, $workload->id])
            ->count())->toBe(2)
        ->and(FirewallRule::query()
            ->where('name', 'orbit-metrics-node-exporter')
            ->where('node_id', $workload->id)
            ->where('owner', 'metrics')
            ->count())->toBe(1);

    app(NodeRoleBaselineConverger::class)->remove($gateway, $assignment, purgeData: false);

    expect(Process::query()
        ->where('name', 'node-exporter')
        ->whereIn('node_id', [$gateway->id, $workload->id])
        ->exists())->toBeFalse()
        ->and(NodeTool::query()
            ->where('name', 'node-exporter')
            ->whereIn('node_id', [$gateway->id, $workload->id])
            ->exists())->toBeFalse()
        ->and(FirewallRule::query()
            ->where('name', 'orbit-metrics-node-exporter')
            ->where('node_id', $workload->id)
            ->where('owner', 'metrics')
            ->exists())->toBeFalse();
});

final class MetricsRoleBaselineRecordingShell implements RemoteShell
{
    /**
     * @var list<array{node: string, script: string}>
     */
    public array $runs = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = [
            'node' => $node->name,
            'script' => $script,
        ];

        if (str_contains($script, 'stream_get_contents(STDIN)')) {
            return new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }

    public function scripts(): string
    {
        return implode("\n", array_column($this->runs, 'script'));
    }

    public function scriptsForNode(string $node): string
    {
        return implode("\n", array_map(
            fn (array $run): string => $run['script'],
            array_filter($this->runs, fn (array $run): bool => $run['node'] === $node),
        ));
    }
}

final class MetricsRoleBaselineRecordingDnsmasqReconciler extends DnsmasqReconciler
{
    public int $reconciles = 0;

    public function __construct() {}

    public function reconcile(): void
    {
        $this->reconciles++;
    }
}
