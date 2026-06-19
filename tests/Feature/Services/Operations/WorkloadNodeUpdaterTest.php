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
            'doctor_issues' => 0,
        ],
        [
            'target' => 'app-dev-1',
            'node' => 'app-dev-1',
            'role' => 'app-dev',
            'status' => 'completed',
            'doctor_issues' => 0,
        ],
        [
            'target' => 'app-prod-1',
            'node' => 'app-prod-1',
            'role' => 'app-prod',
            'status' => 'completed',
            'doctor_issues' => 0,
        ],
        [
            'target' => 'database-1',
            'node' => 'database-1',
            'role' => 'database',
            'status' => 'completed',
            'doctor_issues' => 0,
        ],
        [
            'target' => 'ingress-1',
            'node' => 'ingress-1',
            'role' => 'ingress',
            'status' => 'completed',
            'doctor_issues' => 0,
        ],
    ])
        ->and($shell->updatedNodes())->toBe(['agent-1', 'app-dev-1', 'app-prod-1', 'database-1', 'ingress-1'])
        ->and($shell->calls[0]['options']['metadata'])->toBe(['ORBIT_OPERATION_ID' => $run->id])
        ->and($shell->scriptsFor('agent-1'))->toBe(['orbit --version', $shell->scriptFor('agent-1'), 'orbit doctor --self --json'])
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
        ->toContain('reconcile_launcher')
        ->toContain('command -v orbit')
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

it('skips a workload node already on the target version and runs no remote update', function (): void {
    $shell = new WorkloadUpdaterFakeShell(versions: [
        'app-dev-1' => '2.0.0',
        'app-prod-1' => '1.0.0',
    ]);
    app()->instance(RemoteShell::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'linux']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results)->toMatchArray([
        [
            'target' => 'app-dev-1',
            'node' => 'app-dev-1',
            'role' => 'app-dev',
            'status' => 'skipped',
        ],
        [
            'target' => 'app-prod-1',
            'node' => 'app-prod-1',
            'role' => 'app-prod',
            'status' => 'completed',
            'doctor_issues' => 0,
        ],
    ])
        ->and($shell->updatedNodes())->toBe(['app-prod-1'])
        ->and($shell->scriptsFor('app-dev-1'))->toBe(['orbit --version'])
        ->and(workloadUpdaterStepMessages($run))->toContain(
            ['workload.app-dev-1', 'done', 'Workload node app-dev-1 skipped: already up to date'],
        )
        ->and(UpdateLease::query()->whereNotNull('active_resource_key')->count())->toBe(0);
});

it('runs orbit doctor after a node update and reports the issue count in the done message', function (): void {
    $shell = new WorkloadUpdaterFakeShell(
        doctorIssues: ['app-dev-1' => 2, 'app-prod-1' => 0],
    );
    app()->instance(RemoteShell::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'linux']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results[0]['status'])->toBe('completed')
        ->and($results[0]['doctor_issues'])->toBe(2)
        ->and($results[1]['doctor_issues'])->toBe(0)
        ->and($shell->scriptsFor('app-dev-1'))->toBe(['orbit --version', $shell->scriptFor('app-dev-1'), 'orbit doctor --self --json'])
        ->and(workloadUpdaterStepMessages($run))->toContain(
            ['workload.app-dev-1', 'done', 'Workload node app-dev-1 updated (2 issues)'],
            ['workload.app-prod-1', 'done', 'Workload node app-prod-1 updated'],
        );
});

it('emits per-node sub-steps: downloading, replacing cli binary, running doctor, done', function (): void {
    $shell = new WorkloadUpdaterFakeShell;
    app()->instance(RemoteShell::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    app(WorkloadNodeUpdater::class)->update($run, $plan);

    $messages = workloadUpdaterStepMessages($run);

    expect($messages)->toContain(
        ['workload.app-dev-1', 'running', 'Downloading 2.0.0'],
        ['workload.app-dev-1', 'running', 'Replacing cli binary'],
        ['workload.app-dev-1', 'running', 'Running doctor'],
        ['workload.app-dev-1', 'done', 'Workload node app-dev-1 updated'],
    );

    // Sub-steps must arrive in order
    $nodeMessages = array_values(array_filter(
        $messages,
        fn (array $m): bool => $m[0] === 'workload.app-dev-1',
    ));

    $statuses = array_column($nodeMessages, 1);
    $texts = array_column($nodeMessages, 2);

    $downloadIndex = array_search('Downloading 2.0.0', $texts, true);
    $replaceIndex = array_search('Replacing cli binary', $texts, true);
    $doctorIndex = array_search('Running doctor', $texts, true);
    $doneIndex = array_search('done', $statuses, true);

    expect($downloadIndex)->toBeLessThan($replaceIndex)
        ->and($replaceIndex)->toBeLessThan($doctorIndex)
        ->and($doctorIndex)->toBeLessThan($doneIndex);
});

it('emits skipped sub-step (no download/replace/doctor) for a node already on target', function (): void {
    $shell = new WorkloadUpdaterFakeShell(versions: ['app-dev-1' => '2.0.0']);
    app()->instance(RemoteShell::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    app(WorkloadNodeUpdater::class)->update($run, $plan);

    $messages = workloadUpdaterStepMessages($run);
    $nodeMessages = array_values(array_filter(
        $messages,
        fn (array $m): bool => $m[0] === 'workload.app-dev-1',
    ));
    $texts = array_column($nodeMessages, 2);

    expect($texts)->not->toContain('Downloading 2.0.0')
        ->and($texts)->not->toContain('Replacing cli binary')
        ->and($texts)->not->toContain('Running doctor');
});

it('keeps a non-zero doctor issue count from failing the node update', function (): void {
    $shell = new WorkloadUpdaterFakeShell(doctorIssues: ['app-dev-1' => 5]);
    app()->instance(RemoteShell::class, $shell);

    $run = workloadUpdaterRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, workloadUpdaterSnapshot(targetVersion: '2.0.0'));

    $results = app(WorkloadNodeUpdater::class)->update($run, $plan);

    expect($results[0]['status'])->toBe('completed')
        ->and($results[0]['doctor_issues'])->toBe(5);
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
            'doctor_issues' => 0,
        ],
    ])
        ->and($shell->updatedNodes())->toBe(['app-dev-1', 'app-prod-1'])
        ->and($shell->scriptsFor('app-dev-1'))->toHaveCount(2)
        ->and($shell->scriptsFor('app-prod-1'))->toBe(['orbit --version', $shell->scriptFor('app-prod-1'), 'orbit doctor --self --json'])
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
                        'doctor_issues' => 0,
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
                        'doctor_issues' => 0,
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

    expect($shell->updatedNodes())->toBe([])
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

    expect($shell->updatedNodes())->toBe(['app-dev-1'])
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

/**
 * @return list<array{0: string, 1: string, 2: string|null}>
 */
function workloadUpdaterStepMessages(OperationRun $run): array
{
    return $run->events()
        ->where('event_type', 'step')
        ->get()
        ->map(fn (OperationEvent $event): array => [
            $event->payload['key'],
            $event->payload['status'],
            $event->payload['message'] ?? null,
        ])
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
     * Active leases captured at the moment the remote update script runs.
     *
     * @var array<string, list<string>>
     */
    public array $activeLeases = [];

    /**
     * @param  array<string, RemoteShellResult>  $failures  Keyed by node name; applied to the remote update script call.
     * @param  array<string, string>  $versions  Probed `orbit --version` output keyed by node name (defaults to the target).
     * @param  array<string, int>  $doctorIssues  Per-node doctor issue counts keyed by node name.
     */
    public function __construct(
        private array $failures = [],
        private array $versions = [],
        private array $doctorIssues = [],
        private string $defaultVersion = '0.0.0',
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

        if ($script === 'orbit --version') {
            $version = $this->versions[$node->name] ?? $this->defaultVersion;

            return new RemoteShellResult(exitCode: 0, stdout: "Version       {$version}\n", stderr: '', durationMs: 5);
        }

        if (str_contains($script, 'doctor')) {
            $issues = $this->doctorIssues[$node->name] ?? 0;

            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(['success' => ['data' => ['doctor' => ['summary' => ['issues' => $issues]]]]], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 8,
            );
        }

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
            if ($call['node'] === $node && $call['script'] !== 'orbit --version' && ! str_contains($call['script'], 'doctor')) {
                return $call['script'];
            }
        }

        throw new RuntimeException("No update script recorded for [{$node}].");
    }

    /**
     * @return list<string>
     */
    public function scriptsFor(string $node): array
    {
        return array_values(array_map(
            fn (array $call): string => $call['script'],
            array_filter($this->calls, fn (array $call): bool => $call['node'] === $node),
        ));
    }

    /**
     * @return list<string>
     */
    public function updatedNodes(): array
    {
        $nodes = [];

        foreach ($this->calls as $call) {
            if ($call['script'] !== 'orbit --version' && ! str_contains($call['script'], 'doctor')) {
                $nodes[] = $call['node'];
            }
        }

        return $nodes;
    }
}
