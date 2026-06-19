<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Services\Processes\ProcessServiceDefinitionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not rely on removed tool backfills for service process definitions', function (): void {
    $node = Node::factory()->create(['wireguard_address' => '10.6.0.44']);

    $definition = app(ProcessServiceDefinitionRegistry::class)->resolve(
        definition: 'redis',
        version: '7',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'redis',
    );

    expect(class_exists('App\\Services\\Tools\\ManagedServiceToolProcessBackfill', false))->toBeFalse()
        ->and($definition->runtimeConfig)->toMatchArray([
            'definition' => 'redis',
            'version' => '7.2',
            'image' => 'redis:7.2',
        ]);
});
