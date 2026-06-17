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
        fn (ProcessServiceDefinitionRegistry $registry, Node $node) => $registry->resolve('postgres', '16', ProcessRuntime::Docker, $node, 'postgres16'),
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
]);
