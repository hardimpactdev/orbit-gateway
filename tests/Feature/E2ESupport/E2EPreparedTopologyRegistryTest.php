<?php

declare(strict_types=1);

use App\E2E\Support\E2EPreparedTopologyRegistry;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds app-dev redis with renderable process runtime config', function (): void {
    Node::factory()->appDev()->create([
        'name' => 'app-dev-1',
        'wireguard_address' => '10.6.0.4',
    ]);

    eval(E2EPreparedTopologyRegistry::appdevDatabaseAndRedisPhp());

    $node = Node::query()->where('name', 'app-dev-1')->sole();
    $databaseRole = NodeRoleAssignment::query()
        ->where('node_id', $node->id)
        ->where('role', NodeRoleName::Database->value)
        ->sole();
    $process = Process::query()
        ->where('node_id', $node->id)
        ->where('name', 'redis')
        ->sole();

    expect($databaseRole->status)->toBe(NodeRoleStatus::Active)
        ->and($process->runtime)->toBe(ProcessRuntime::Docker)
        ->and($process->command)->toBe('redis-server --appendonly yes --bind 0.0.0.0 --protected-mode no')
        ->and($process->runtime_config)->toMatchArray([
            'definition' => 'redis',
            'version_family' => '7',
            'version' => '7.2',
            'image' => 'redis:7.2',
            'service_name' => 'orbit-redis',
            'endpoint' => [
                'kind' => 'tcp',
                'name' => 'redis',
                'host' => '10.6.0.4',
                'port' => 6379,
            ],
        ])
        ->and($process->runtime_config['labels']['orbit.process'])->toBe('redis')
        ->and($process->runtime_config['mounts'][0]['source'])->toBe('/var/lib/orbit/processes/redis');
});
