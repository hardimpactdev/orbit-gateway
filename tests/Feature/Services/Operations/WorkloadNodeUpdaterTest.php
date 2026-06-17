<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\UpdateLeaseConflict;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\OperationEvent;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Models\UpdateLease;
use App\Services\Operations\FleetUpdateVerifier;
use App\Services\Operations\GatewayServiceUpdater;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdateLeaseManager;
use App\Services\Operations\UpdateRunner;
use App\Services\Operations\WorkloadNodeUpdateFailed;
use App\Services\Operations\WorkloadNodeUpdater;
use App\Services\RemoteShell\RemoteShellMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

it('updates active non-gateway managed nodes from the persisted manifest snapshot', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RemoteShell::class, $shell);

    $run = workloadUpdaterRun();
    $appDev = Node::factory()->appDev()->create([
        'name' => 'app-dev-1',
        'platform' => 'ubuntu_24-04',
        'orbit_path' => '/opt/orbit-app-dev',
    ]);
    $appProd = Node::factory()->appProd()->create([
        'name' => 'app-prod-1',
        'platform' => 'ubuntu',
        'orbit_path' => '/opt/orbit-app-prod',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $appProd->id,
        'role' => 'websocket',
        'status' => 'active',
    ]);
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->database()->create(['name' => 'database-1', 'platform' => 'ubuntu']);
    Node::factory()->ingress()->create(['name' => 'ingress-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->gateway()->appDev()->create(['name' => 'gateway-app']);
    Node::factory()->operator()->create(['name' => 'operator-1']);

    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        workloadUpdaterSnapshot(
            targetVersion: '2.0.0',
            cliArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-linux-amd64',
                    'sha256' => str_repeat('e', 64),
                ],
            ],
            roleImages: [
                'orbit-caddy' => 'caddy:2.9-alpine',
                'orbit-websocket' => 'hardimpact/orbit-reverb:2.0.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
            ],
        ),
    );

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)->toMatchArray([
        [
            'target' => 'agent-1',
            'node' => 'agent-1',
            'role' => 'agent',
            'status' => 'completed',
        ],
        [
            'target' => 'app-dev-1',
            'node' => 'app-dev-1',
            'role' => 'app-dev',
            'status' => 'completed',
        ],
        [
            'target' => 'app-prod-1',
            'node' => 'app-prod-1',
            'role' => 'app-prod',
            'status' => 'completed',
        ],
        [
            'target' => 'database-1',
            'node' => 'database-1',
            'role' => 'database',
            'status' => 'completed',
        ],
        [
            'target' => 'ingress-1',
            'node' => 'ingress-1',
            'role' => 'ingress',
            'status' => 'completed',
        ],
    ])
        ->and($shell->calls)->toHaveCount(5)
        ->and($shell->calls[0]['options']['metadata'])->toBe(['ORBIT_OPERATION_ID' => $run->id])
        ->and($shell->calls[4]['options']['metadata'])->toBe(['ORBIT_OPERATION_ID' => $run->id])
        ->and($shell->activeLeases)->toBe([
            'agent-1' => ['node:agent-1'],
            'app-dev-1' => ['node:app-dev-1'],
            'app-prod-1' => ['node:app-prod-1'],
            'database-1' => ['node:database-1'],
            'ingress-1' => ['node:ingress-1'],
        ])
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())->toBe(0);

    expect($shell->scriptFor('agent-1'))
        ->toContain('download_cli')
        ->not->toContain('pull_required_images');

    expect($shell->scriptFor('app-dev-1'))
        ->toContain('download_cli')
        ->toContain('install_cli')
        ->toContain('verify_cli')
        ->toContain('sudo -n ln -sfn')
        ->toContain('pull_required_images')
        ->toContain('https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-linux-amd64')
        ->toContain(str_repeat('e', 64))
        ->toContain("docker pull 'caddy:2.9-alpine'")
        ->not->toContain('orbit-websocket:2.0.0');

    expect($shell->scriptFor('app-prod-1'))
        ->toContain("docker pull 'caddy:2.9-alpine'")
        ->toContain("docker pull 'hardimpact/orbit-reverb:2.0.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'");

    expect($shell->scriptFor('database-1'))
        ->toContain('download_cli')
        ->not->toContain('pull_required_images');

    expect($shell->scriptFor('ingress-1'))
        ->toContain("docker pull 'caddy:2.9-alpine'")
        ->not->toContain('orbit-websocket:2.0.0');
});

it('continues updating later workload nodes when one remote update fails', function (): void {
    $shell = new WorkloadUpdaterFakeShell(failures: [
        'app-dev-1' => new RemoteShellResult(exitCode: 12, stdout: '', stderr: 'download failed', durationMs: 10),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'linux']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot());

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)->toMatchArray([
        [
            'target' => 'app-dev-1',
            'node' => 'app-dev-1',
            'role' => 'app-dev',
            'status' => 'failed',
            'failed_step' => 'remote_update',
            'output' => 'download failed',
        ],
        [
            'target' => 'app-prod-1',
            'node' => 'app-prod-1',
            'role' => 'app-prod',
            'status' => 'completed',
        ],
    ])
        ->and($shell->calls)->toHaveCount(2)
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())->toBe(0);
});

it('fails the runner workload phase with target results when any workload update fails', function (): void {
    $shell = new WorkloadUpdaterFakeShell(failures: [
        'app-dev-1' => new RemoteShellResult(exitCode: 12, stdout: '', stderr: 'download failed', durationMs: 10),
    ]);
    app()->instance(RemoteShell::class, $shell);
    app()->instance(GatewayServiceUpdater::class, new WorkloadUpdaterNoopGatewayUpdater);
    app()->instance(FleetUpdateVerifier::class, new WorkloadUpdaterFailIfCalledFleetVerifier);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'linux']);
    app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot());

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(WorkloadNodeUpdateFailed::class, 'One or more workload nodes failed to update.');

    $run->refresh();
    $error = OperationEvent::query()
        ->where('operation_run_id', $run->id)
        ->where('event_type', 'error')
        ->firstOrFail();

    expect($run->status)->toBe(OperationStatus::Failed)
        ->and($run->error)->toMatchArray([
            'code' => 'workload_update_failed',
            'message' => 'One or more workload nodes failed to update.',
            'data' => [
                'failed_targets' => [
                    [
                        'target' => 'app-dev-1',
                        'node' => 'app-dev-1',
                        'role' => 'app-dev',
                        'status' => 'failed',
                        'failed_step' => 'remote_update',
                        'output' => 'download failed',
                    ],
                ],
                'target_results' => [
                    [
                        'target' => 'app-dev-1',
                        'node' => 'app-dev-1',
                        'role' => 'app-dev',
                        'status' => 'failed',
                        'failed_step' => 'remote_update',
                        'output' => 'download failed',
                    ],
                    [
                        'target' => 'app-prod-1',
                        'node' => 'app-prod-1',
                        'role' => 'app-prod',
                        'status' => 'completed',
                    ],
                ],
            ],
        ])
        ->and($error->payload)->toMatchArray([
            'exit_code' => 1,
            'data' => [
                'code' => 'workload_update_failed',
                'failed_targets' => [
                    [
                        'target' => 'app-dev-1',
                        'node' => 'app-dev-1',
                        'role' => 'app-dev',
                        'status' => 'failed',
                        'failed_step' => 'remote_update',
                        'output' => 'download failed',
                    ],
                ],
                'target_results' => [
                    [
                        'target' => 'app-dev-1',
                        'node' => 'app-dev-1',
                        'role' => 'app-dev',
                        'status' => 'failed',
                        'failed_step' => 'remote_update',
                        'output' => 'download failed',
                    ],
                    [
                        'target' => 'app-prod-1',
                        'node' => 'app-prod-1',
                        'role' => 'app-prod',
                        'status' => 'completed',
                    ],
                ],
            ],
        ])
        ->and(workloadUpdaterStepEvents($run))->toContain(
            ['workload.app-dev-1', 'fail'],
            ['workload.app-prod-1', 'done'],
            ['workload-nodes', 'fail'],
        )
        ->and(workloadUpdaterStepEvents($run))->not->toContain(['verification', 'running']);
});

it('fails the update operation when a workload node lease is already held', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(GatewayServiceUpdater::class, new WorkloadUpdaterNoopGatewayUpdater);
    app()->instance(FleetUpdateVerifier::class, new WorkloadUpdaterNoopFleetVerifier);

    $run = workloadUpdaterRun();
    $conflictingNode = Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'linux']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot());
    $otherRun = workloadUpdaterRun();

    $conflictingLease = app(UpdateLeaseManager::class)->acquire(
        resourceType: 'node',
        resourceKey: $conflictingNode->name,
        operationRun: $otherRun,
        ownerToken: 'other-owner',
        ttlSeconds: 300,
    );

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(UpdateLeaseConflict::class);

    $error = OperationEvent::query()
        ->where('operation_run_id', $run->id)
        ->where('event_type', 'error')
        ->firstOrFail();

    expect($shell->calls)->toBe([])
        ->and($run->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($run->error)->toMatchArray([
            'code' => 'update.node_locked',
            'message' => 'Update resource [node:app-dev-1] is already leased by operation ['.$otherRun->id.'] until '.$conflictingLease->expires_at->toIso8601String().'.',
            'data' => [
                'resource' => 'node:app-dev-1',
                'resource_type' => 'node',
                'resource_key' => 'app-dev-1',
                'lease_id' => $conflictingLease->id,
                'conflicting_operation_id' => $otherRun->id,
                'expires_at' => $conflictingLease->expires_at->toIso8601String(),
            ],
        ])
        ->and($error->payload)->toMatchArray([
            'exit_code' => 1,
            'data' => [
                'code' => 'update.node_locked',
                'resource' => 'node:app-dev-1',
                'resource_type' => 'node',
                'resource_key' => 'app-dev-1',
                'lease_id' => $conflictingLease->id,
                'conflicting_operation_id' => $otherRun->id,
                'expires_at' => $conflictingLease->expires_at->toIso8601String(),
            ],
        ])
        ->and(workloadUpdaterStepEvents($run))->toContain(
            ['workload-nodes', 'running'],
            ['workload.app-dev-1', 'running'],
            ['workload.app-dev-1', 'fail'],
            ['workload-nodes', 'fail'],
        )
        ->and(workloadUpdaterStepEvents($run))->not->toContain(['workload.app-prod-1', 'running'])
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->pluck('resource_key')->all())->toBe(['app-dev-1']);
});

it('is invoked by the default update runner pipeline while the fleet lease is active', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RemoteShell::class, $shell);
    app()->instance(GatewayServiceUpdater::class, new WorkloadUpdaterNoopGatewayUpdater);
    app()->instance(FleetUpdateVerifier::class, new WorkloadUpdaterNoopFleetVerifier);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot());

    app(UpdateRunner::class)->run($run->id);

    expect($shell->calls)->toHaveCount(1)
        ->and($shell->activeLeases)->toBe([
            'app-dev-1' => ['fleet:update-all', 'node:app-dev-1'],
        ])
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())->toBe(0);
});

function workloadUpdaterRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

/**
 * @return list<array{0: string, 1: string}>
 */
function workloadUpdaterStepEvents(OperationRun $run): array
{
    return $run->events()
        ->where('event_type', 'step')
        ->get()
        ->map(fn (OperationEvent $event): array => [$event->payload['key'], $event->payload['status']])
        ->all();
}

class WorkloadUpdaterNoopGatewayUpdater extends GatewayServiceUpdater
{
    #[Override]
    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }
}

final class WorkloadUpdaterNoopFleetVerifier extends FleetUpdateVerifier
{
    public function __construct() {}

    #[Override]
    public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }
}

final class WorkloadUpdaterFailIfCalledFleetVerifier extends FleetUpdateVerifier
{
    public function __construct() {}

    #[Override]
    public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        throw new RuntimeException('Fleet verification should not run after a workload update failure.');
    }
}

/**
 * @param  array<string, array{url: string, sha256: string}>  $cliArtifacts
 * @param  array<string, string>  $roleImages
 */
function workloadUpdaterSnapshot(
    string $targetVersion = '1.2.3',
    string $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    array $cliArtifacts = [],
    array $roleImages = [],
): OperationUpdatePlanSnapshot {
    $cliArtifacts = $cliArtifacts === [] ? [
        'linux-amd64' => [
            'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-amd64',
            'sha256' => str_repeat('b', 64),
        ],
    ] : $cliArtifacts;
    $roleImages = $roleImages === [] ? [
        'orbit-caddy' => 'caddy:2-alpine',
        'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
    ] : $roleImages;

    return new OperationUpdatePlanSnapshot(
        targetVersion: $targetVersion,
        gatewayImage: $gatewayImage,
        manifestSource: 'github-release',
        manifestVersion: $targetVersion,
        manifestSnapshot: [
            'version' => $targetVersion,
            'source' => 'github-release',
            'images' => [
                'gateway' => $gatewayImage,
            ],
            'cli_artifacts' => $cliArtifacts,
            'role_images' => $roleImages,
        ],
        cliArtifacts: $cliArtifacts,
        roleImages: $roleImages,
    );
}

final class WorkloadUpdaterFakeShell implements RemoteShell
{
    /**
     * @var list<array{node: string, script: string, options: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @var array<string, list<string>>
     */
    public array $activeLeases = [];

    /**
     * @param  array<string, RemoteShellResult>  $failures
     */
    public function __construct(
        private array $failures = [],
    ) {}

    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        (new RemoteShellMetadata)->prologue($options['metadata'] ?? []);

        $this->calls[] = [
            'node' => $node->name,
            'script' => $script,
            'options' => $options,
        ];
        $this->activeLeases[$node->name] = UpdateLease::query()
            ->whereNotNull('active_resource_key')
            ->orderBy('id')
            ->get()
            ->map(fn (UpdateLease $lease): string => "{$lease->resource_type}:{$lease->resource_key}")
            ->all();

        return $this->failures[$node->name]
            ?? new RemoteShellResult(exitCode: 0, stdout: "updated\n", stderr: '', durationMs: 20);
    }

    public function scriptFor(string $node): string
    {
        foreach ($this->calls as $call) {
            if ($call['node'] === $node) {
                return $call['script'];
            }
        }

        throw new RuntimeException("No script recorded for [{$node}].");
    }
}
