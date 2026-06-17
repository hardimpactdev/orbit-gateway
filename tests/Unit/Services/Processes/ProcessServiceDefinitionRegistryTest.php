<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Processes\ProcessServiceDefinitionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves MySQL and Redis service definitions into process runtime config', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'wireguard_address' => '10.6.0.44',
    ]);

    $mysql = app(ProcessServiceDefinitionRegistry::class)->resolve(
        definition: 'mysql',
        version: '8',
        runtime: ProcessRuntime::DockerSwarm,
        node: $node,
        processName: 'mysql8',
    );

    $redis = app(ProcessServiceDefinitionRegistry::class)->resolve(
        definition: 'redis',
        version: '7',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'redis',
    );

    expect($mysql->command)->toBe('mysqld')
        ->and($mysql->versionFamily)->toBe('8')
        ->and($mysql->version)->toBe('8.4')
        ->and($mysql->runtimeConfig)->toMatchArray([
            'definition' => 'mysql',
            'version_family' => '8',
            'version' => '8.4',
            'image' => 'mysql:8.4',
            'service_name' => 'orbit-mysql8',
        ])
        ->and($mysql->runtimeConfig['endpoint']['name'])->toBe('mysql8')
        ->and($mysql->runtimeConfig['endpoint']['host'])->toBe('10.6.0.44')
        ->and($mysql->runtimeConfig['endpoint']['port'])->toBe(3308)
        ->and($mysql->runtimeConfig['labels']['orbit.process'])->toBe('mysql8')
        ->and($mysql->runtimeConfig['labels']['orbit.process.definition'])->toBe('mysql')
        ->and($mysql->runtimeConfig['labels']['orbit.process.version_family'])->toBe('8')
        ->and($mysql->runtimeConfig['labels']['orbit.process.version'])->toBe('8.4')
        ->and($mysql->runtimeConfig['labels']['orbit.process.spec_hash'])->toBe($mysql->runtimeConfig['spec_hash'])
        ->and($mysql->runtimeConfig['volumes'][0]['name'])->toBe('orbit-mysql8')
        ->and($mysql->runtimeConfig['mounts'][0]['source'])->toBe('/var/lib/orbit/processes/mysql8')
        ->and($redis->command)->toContain('redis-server')
        ->and($redis->runtimeConfig)->toMatchArray([
            'definition' => 'redis',
            'version_family' => '7',
            'version' => '7.2',
            'image' => 'redis:7.2',
        ])
        ->and($redis->runtimeConfig['endpoint']['name'])->toBe('redis')
        ->and($redis->runtimeConfig['endpoint']['host'])->toBe('10.6.0.44')
        ->and($redis->runtimeConfig['endpoint']['port'])->toBe(6379);
});

it('keeps MySQL 8 and MySQL 9 process definitions distinct', function (): void {
    $node = Node::factory()->create(['wireguard_address' => '10.6.0.44']);
    $registry = app(ProcessServiceDefinitionRegistry::class);

    $mysql8 = $registry->resolve('mysql', '8', ProcessRuntime::Docker, $node, 'mysql8');
    $mysql9 = $registry->resolve('mysql', '9', ProcessRuntime::Docker, $node, 'mysql9');

    expect($mysql8->runtimeConfig['endpoint']['port'])->toBe(3308)
        ->and($mysql9->runtimeConfig['endpoint']['port'])->toBe(3309)
        ->and($mysql8->runtimeConfig['service_name'])->toBe('orbit-mysql8')
        ->and($mysql9->runtimeConfig['service_name'])->toBe('orbit-mysql9')
        ->and($mysql8->runtimeConfig['spec_hash'])->not->toBe($mysql9->runtimeConfig['spec_hash']);
});

it('resolves metrics service definitions for Prometheus, Grafana, and node-exporter', function (): void {
    $node = Node::factory()->create([
        'name' => 'metrics-1',
        'wireguard_address' => '10.6.0.55',
    ]);

    $registry = app(ProcessServiceDefinitionRegistry::class);

    $prometheus = $registry->resolve(
        definition: 'prometheus',
        version: null,
        runtime: ProcessRuntime::DockerSwarm,
        node: $node,
        processName: 'prometheus',
    );
    $grafana = $registry->resolve(
        definition: 'grafana',
        version: null,
        runtime: ProcessRuntime::DockerSwarm,
        node: $node,
        processName: 'grafana',
    );
    $nodeExporter = $registry->resolve(
        definition: 'node-exporter',
        version: null,
        runtime: ProcessRuntime::Systemd,
        node: $node,
        processName: 'node-exporter',
    );

    expect($prometheus->version)->toBe('v3.12.0')
        ->and($prometheus->command)->toContain('--storage.tsdb.retention.time=15d')
        ->and($prometheus->runtimeConfig)->toMatchArray([
            'definition' => 'prometheus',
            'version_family' => '3',
            'version' => 'v3.12.0',
            'image' => 'prom/prometheus:v3.12.0',
            'service_name' => 'orbit-prometheus',
        ])
        ->and($prometheus->runtimeConfig['endpoint']['host'])->toBe('10.6.0.55')
        ->and($prometheus->runtimeConfig['endpoint']['port'])->toBe(9090)
        ->and($prometheus->runtimeConfig['labels']['orbit.process.definition'])->toBe('prometheus')
        ->and($grafana->version)->toBe('13.0.2')
        ->and($grafana->runtimeConfig)->toMatchArray([
            'definition' => 'grafana',
            'version_family' => '13',
            'version' => '13.0.2',
            'image' => 'grafana/grafana:13.0.2',
            'service_name' => 'orbit-grafana',
            'environment' => [
                'GF_SECURITY_ADMIN_USER' => 'admin',
                'GF_SERVER_ROOT_URL' => 'https://metrics.orbit',
            ],
        ])
        ->and($grafana->runtimeConfig['endpoint']['host'])->toBe('10.6.0.55')
        ->and($grafana->runtimeConfig['endpoint']['port'])->toBe(3000)
        ->and($nodeExporter->version)->toBe('1.11.1')
        ->and($nodeExporter->command)->toContain('node_exporter')
        ->and($nodeExporter->runtimeConfig)->toMatchArray([
            'definition' => 'node-exporter',
            'version_family' => '1',
            'version' => '1.11.1',
            'endpoint' => [
                'name' => 'node-exporter',
                'kind' => 'tcp',
                'host' => '10.6.0.55',
                'port' => 9100,
            ],
        ])
        ->and($nodeExporter->runtimeConfig['labels']['orbit.process.definition'])->toBe('node-exporter');
});

it('resolves PostgreSQL, ClickHouse, and Plausible service definitions into process runtime config', function (): void {
    $node = Node::factory()->create([
        'name' => 'analytics-1',
        'wireguard_address' => '10.6.0.50',
    ]);

    $registry = app(ProcessServiceDefinitionRegistry::class);

    $postgres = $registry->resolve('postgres', '16', ProcessRuntime::DockerSwarm, $node, 'postgres16');
    $clickhouse = $registry->resolve('clickhouse', '24', ProcessRuntime::DockerSwarm, $node, 'clickhouse24');
    $plausible = $registry->resolve('plausible', '3.2.2', ProcessRuntime::DockerSwarm, $node, 'plausible');

    expect($registry->names())->toContain('postgres', 'clickhouse', 'plausible')
        ->and($postgres->runtimeConfig)->toMatchArray([
            'definition' => 'postgres',
            'version_family' => '16',
            'version' => '16',
            'image' => 'postgres:16',
        ])
        ->and($postgres->runtimeConfig['endpoint']['port'])->toBe(5432)
        ->and($clickhouse->runtimeConfig)->toMatchArray([
            'definition' => 'clickhouse',
            'version_family' => '24',
            'version' => '24',
            'image' => 'clickhouse/clickhouse-server:24',
        ])
        ->and($clickhouse->runtimeConfig['endpoint']['port'])->toBe(8123)
        ->and($plausible->runtimeConfig)->toMatchArray([
            'definition' => 'plausible',
            'version_family' => '3.2.2',
            'version' => '3.2.2',
            'image' => 'ghcr.io/plausible/community-edition:3.2.2',
        ])
        ->and($plausible->runtimeConfig['endpoint']['port'])->toBe(8000)
        ->and($plausible->runtimeConfig['environment'])->toMatchArray([
            'BASE_URL' => 'https://analytics.orbit',
        ])
        ->and($plausible->runtimeConfig['labels']['orbit.process.definition'])->toBe('plausible')
        ->and($plausible->runtimeConfig['labels']['orbit.process.version'])->toBe('3.2.2');
});

it('requires service process endpoints to use the owner node WireGuard address', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'host' => 'database-1.example.com',
        'wireguard_address' => '10.6.0.44',
    ]);

    $definition = app(ProcessServiceDefinitionRegistry::class)->resolve(
        definition: 'redis',
        version: '7',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'redis',
    );

    expect($definition->runtimeConfig['endpoint']['host'])->toBe('10.6.0.44')
        ->and($definition->runtimeConfig['endpoints'][0]['host'])->toBe('10.6.0.44')
        ->and($definition->runtimeConfig['endpoint']['host'])->not->toBe('database-1.example.com')
        ->and($definition->runtimeConfig['endpoint']['host'])->not->toBe('database-1');
});

it('rejects service process endpoints when the owning node has no WireGuard address', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'host' => 'database-1.example.com',
        'wireguard_address' => null,
    ]);

    app(ProcessServiceDefinitionRegistry::class)->resolve(
        definition: 'redis',
        version: '7',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'redis',
    );
})->throws(GatewayApiException::class, "Node 'database-1' cannot host service process endpoints without a WireGuard address.");

it('rejects unsupported service process definition inputs', function (Closure $operation, string $field, string $reason): void {
    $node = Node::factory()->create();

    try {
        $operation(app(ProcessServiceDefinitionRegistry::class), $node);
    } catch (GatewayApiException $exception) {
        expect($exception->errorCode())->toBe('validation_failed')
            ->and($exception->errorMeta())->toMatchArray([
                'field' => $field,
                'reason' => $reason,
            ]);

        return;
    }

    $this->fail('Expected GatewayApiException was not thrown.');
})->with([
    'definition' => [
        fn (ProcessServiceDefinitionRegistry $registry, Node $node) => $registry->resolve('queue', '1', ProcessRuntime::Docker, $node, 'queue'),
        'definition',
        'unsupported_value',
    ],
    'version required' => [
        fn (ProcessServiceDefinitionRegistry $registry, Node $node) => $registry->resolve('mysql', null, ProcessRuntime::Docker, $node, 'mysql'),
        'version',
        'required',
    ],
    'version unsupported' => [
        fn (ProcessServiceDefinitionRegistry $registry, Node $node) => $registry->resolve('mysql', '10', ProcessRuntime::Docker, $node, 'mysql10'),
        'version',
        'unsupported_value',
    ],
    'runtime unsupported' => [
        fn (ProcessServiceDefinitionRegistry $registry, Node $node) => $registry->resolve('redis', '7', ProcessRuntime::Systemd, $node, 'redis'),
        'runtime',
        'process_definition_runtime_unsupported',
    ],
    'node exporter docker unsupported' => [
        fn (ProcessServiceDefinitionRegistry $registry, Node $node) => $registry->resolve('node-exporter', null, ProcessRuntime::DockerSwarm, $node, 'node-exporter'),
        'runtime',
        'process_definition_runtime_unsupported',
    ],
]);
