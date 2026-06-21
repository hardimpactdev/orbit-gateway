<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Services\Processes\ProcessServiceDefinitionRegistry;
use App\Services\Tools\ToolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('selects service versions from process definitions instead of tool instances', function (): void {
    $node = Node::factory()->create(['wireguard_address' => '10.6.0.44']);
    $registry = app(ProcessServiceDefinitionRegistry::class);

    $mysql8 = $registry->resolve('mysql', '8', ProcessRuntime::Docker, $node, 'mysql8');
    $mysql84 = $registry->resolve('mysql', '8.4', ProcessRuntime::Docker, $node, 'mysql8-alt');

    expect(app(ToolCatalog::class)->supports('mysql'))->toBeFalse()
        ->and($mysql8->versionFamily)->toBe('8')
        ->and($mysql8->version)->toBe('8.4')
        ->and($mysql84->versionFamily)->toBe('8')
        ->and($mysql84->version)->toBe('8.4');
});

it('rejects unsupported service process definition versions', function (): void {
    $node = Node::factory()->create();

    app(ProcessServiceDefinitionRegistry::class)->resolve(
        definition: 'mysql',
        version: '10',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'mysql10',
    );
})->throws(GatewayApiException::class, "Process definition 'mysql' does not support version '10'.");
