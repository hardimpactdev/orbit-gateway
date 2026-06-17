<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->metricsShell = new MetricsRoleBaselineRecordingShell;

    app()->instance(RemoteShell::class, $this->metricsShell);
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

    expect($processes->keys()->all())->toBe(['grafana', 'node-exporter', 'prometheus'])
        ->and($processes['grafana']->owner_type)->toBe($node->getMorphClass())
        ->and($processes['grafana']->runtime)->toBe(ProcessRuntime::DockerSwarm)
        ->and($processes['grafana']->runtime_config['definition'])->toBe('grafana')
        ->and($processes['grafana']->runtime_config['endpoint']['port'])->toBe(3000)
        ->and($processes['grafana']->runtime_config['environment']['GF_SECURITY_ADMIN_PASSWORD'])->toBeString()
        ->and($processes['grafana']->runtime_config['credentials']['admin_password'])->toBe(
            $processes['grafana']->runtime_config['environment']['GF_SECURITY_ADMIN_PASSWORD'],
        )
        ->and($processes['grafana']->runtime_config['managed_files'][0]['content'])->toContain("url: 'http://10.6.0.55:9090'")
        ->and($processes['grafana']->runtime_config['bind_mounts'][0])->toMatchArray([
            'source' => '/var/lib/orbit/processes/grafana/provisioning/datasources/prometheus.yml',
            'target' => '/etc/grafana/provisioning/datasources/prometheus.yml',
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

    $scripts = $this->metricsShell->scripts();

    expect($scripts)
        ->toContain('node_exporter_url=')
        ->toContain('/var/lib/orbit/processes/prometheus/prometheus.yml')
        ->toContain('/var/lib/orbit/processes/grafana/provisioning/datasources/prometheus.yml')
        ->toContain("docker service update --replicas 1 'orbit-prometheus'")
        ->toContain("docker service update --replicas 1 'orbit-grafana'")
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
        ]);
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
        ->toContain("sudo systemctl start 'node-exporter.service'");
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
            ->count())->toBe(2);

    app(NodeRoleBaselineConverger::class)->remove($gateway, $assignment, purgeData: false);

    expect(Process::query()
        ->where('name', 'node-exporter')
        ->whereIn('node_id', [$gateway->id, $workload->id])
        ->exists())->toBeFalse()
        ->and(NodeTool::query()
            ->where('name', 'node-exporter')
            ->whereIn('node_id', [$gateway->id, $workload->id])
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
