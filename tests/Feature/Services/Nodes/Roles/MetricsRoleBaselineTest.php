<?php

declare(strict_types=1);

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

    expect(NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'docker')
        ->where('expected_state', 'installed')
        ->exists())->toBeTrue();

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
        ->and($processes['prometheus']->runtime)->toBe(ProcessRuntime::DockerSwarm)
        ->and($processes['prometheus']->runtime_config['definition'])->toBe('prometheus')
        ->and($processes['prometheus']->runtime_config['endpoint']['port'])->toBe(9090)
        ->and($processes['node-exporter']->runtime)->toBe(ProcessRuntime::Systemd)
        ->and($processes['node-exporter']->runtime_config['definition'])->toBe('node-exporter')
        ->and($processes['node-exporter']->runtime_config['endpoint']['port'])->toBe(9100);

    $route = ProxyRoute::query()->where('domain', 'metrics.orbit')->sole();

    expect($route->node_id)->toBe($router->id)
        ->and($route->owner_type)->toBe('router')
        ->and($route->kind)->toBe('proxy')
        ->and($route->config)->toMatchArray([
            'owner_name' => 'grafana',
            'protocol' => 'http',
            'target' => [
                'type' => 'upstream',
                'value' => 'http://metrics-1.metrics.orbit:3000',
            ],
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'metrics-1.metrics.orbit', 'port' => 3000],
            ],
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
