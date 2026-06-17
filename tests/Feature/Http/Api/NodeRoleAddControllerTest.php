<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/NodeRoleApiTestHelpers.php';

describe('NodeRoleAddController', function (): void {
    it('adds a role for an authorized caller and returns the assignment payload', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'database',
            'settings' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.assignment.role', 'database')
            ->assertJsonPath('success.data.assignment.status', 'active')
            ->assertJsonPath('success.data.assignment.settings', [])
            ->assertJsonPath('success.data.assignment.last_error', null);

        expect($target->roleAssignments()->where('role', 'database')->where('status', NodeRoleStatus::Active->value)->exists())->toBeTrue();
    });

    it('reconverges an existing metrics role when requested', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);
        createNodeRoleApiContractAssignment($target, 'metrics');
        app()->instance(RemoteShell::class, new NodeRoleAddMetricsRecordingShell);

        Process::factory()->forOwner($target)->create([
            'name' => 'prometheus',
            'command' => 'prometheus --config.file=/etc/prometheus/prometheus.yml',
            'runtime' => ProcessRuntime::DockerSwarm,
            'runtime_config' => [
                'definition' => 'prometheus',
                'endpoint' => ['port' => 9090],
            ],
        ]);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'metrics',
            'settings' => [],
            'reconverge_existing' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.assignment.role', 'metrics')
            ->assertJsonPath('success.data.assignment.status', 'active');

        $prometheus = Process::query()
            ->where('node_id', $target->id)
            ->where('name', 'prometheus')
            ->sole();

        expect($prometheus->runtime_config['managed_files'][0]['content'])->toContain("'10.6.0.20:9100'")
            ->and($prometheus->runtime_config['bind_mounts'][0])->toMatchArray([
                'source' => '/var/lib/orbit/processes/prometheus/prometheus.yml',
                'target' => '/etc/prometheus/prometheus.yml',
                'read_only' => true,
            ]);
    });

    it('rejects reconverge existing for non metrics roles', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'database',
            'settings' => [],
            'reconverge_existing' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'reconverge_existing')
            ->assertJsonPath('error.meta.role', 'database');

        expect($target->roleAssignments()->where('role', 'database')->exists())->toBeFalse();
    });

    it('rejects gateway role additions before side effects', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'gateway',
            'settings' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', "Role 'gateway' is gateway-coupled and cannot be assigned independently.")
            ->assertJsonPath('error.meta.field', 'role')
            ->assertJsonPath('error.meta.role', 'gateway')
            ->assertJsonMissingPath('success');

        expect($target->roleAssignments()->where('role', 'gateway')->exists())->toBeFalse();
    });

    it('returns the authorized caller response shape', function (): void {
        [, , $target] = setUpNodeRoleApiContractAccess(['role:add']);

        $response = postNodeRoleApiContractJson('/api/nodes/target-1/roles', [
            'role' => 'app-dev',
            'settings' => ['tld' => 'test'],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success' => [
                    'data' => [
                        'node',
                        'assignment' => [
                            'role',
                            'status',
                            'settings',
                            'last_error',
                            'converged_at',
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('success.data.node', 'target-1')
            ->assertJsonPath('success.data.assignment.settings.tld', 'test');

        $selfGrant = NodeAccess::query()
            ->where('consumer_node_id', $target->id)
            ->where('serving_node_id', $target->id)
            ->first();

        expect($selfGrant?->permissions)->toBe(['workspace:setup'])
            ->and($selfGrant?->custom_permissions)->toBe([]);
    });
});

final class NodeRoleAddMetricsRecordingShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
