<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Processes\ProcessServiceDefinitionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps service process runtime selection independent of node tool platform rows', function (): void {
    $node = Node::factory()->create([
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.44',
    ]);

    $definition = app(ProcessServiceDefinitionRegistry::class)->resolve(
        definition: 'redis',
        version: '7',
        runtime: ProcessRuntime::DockerSwarm,
        node: $node,
        processName: 'redis',
    );

    expect($definition->runtimeConfig['image'])->toBe('redis:7.2')
        ->and($definition->runtimeConfig['endpoint']['host'])->toBe('10.6.0.44')
        ->and($definition->runtimeConfig['labels']['orbit.process.definition'])->toBe('redis');
});

it('rejects unsupported process definition runtimes through the process definition registry', function (): void {
    $node = Node::factory()->create();

    app(ProcessServiceDefinitionRegistry::class)->resolve(
        definition: 'redis',
        version: '7',
        runtime: ProcessRuntime::Systemd,
        node: $node,
        processName: 'redis',
    );
})->throws(GatewayApiException::class, "Process definition 'redis' does not support runtime 'systemd'.");
