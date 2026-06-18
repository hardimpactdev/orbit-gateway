<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Services\Processes\ProcessServiceDefinitionRegistry;
use App\Services\Tools\ToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps service runtime configuration in process definitions instead of tools', function (): void {
    $node = Node::factory()->create(['wireguard_address' => '10.6.0.44']);
    $definition = app(ProcessServiceDefinitionRegistry::class)->resolve(
        definition: 'mysql',
        version: '8',
        runtime: ProcessRuntime::DockerSwarm,
        node: $node,
        processName: 'mysql8',
    );

    expect(app(ToolCatalog::class)->supports('mysql'))->toBeFalse()
        ->and($definition->runtimeConfig)->toMatchArray([
            'definition' => 'mysql',
            'version_family' => '8',
            'version' => '8.4',
            'service_name' => 'orbit-mysql8',
        ])
        ->and($definition->runtimeConfig['labels']['orbit.process.definition'])->toBe('mysql')
        ->and($definition->runtimeConfig['labels']['orbit.process.version_family'])->toBe('8')
        ->and($definition->runtimeConfig['labels'])->not->toHaveKey('orbit.tool')
        ->and($definition->runtimeConfig['labels'])->not->toHaveKey('orbit.tool_instance');
});
