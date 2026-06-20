<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationEvent;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdateRunner;
use App\Services\Operations\UpdateRunnerPipeline;
use App\Services\RemoteShell\RemoteShellMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

it('emits the two check steps before the gateway phase and reports outdated nodes', function (): void {
    config()->set('app.version', '2.0.0');

    app()->instance(RemoteShell::class, new CheckStepsFakeShell(versions: [
        'agent-1' => '1.0.0',
        'app-dev-1' => '2.0.0',
    ]));
    app()->instance(UpdateRunnerPipeline::class, new CheckStepsNoopPipeline);

    $run = checkStepsRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);

    app(OperationUpdatePlanStore::class)->create($run, checkStepsSnapshot('2.0.0'));

    app(UpdateRunner::class)->run($run->id);

    $steps = checkStepsEvents($run);

    expect($steps)->toContain(
        ['check-updates', 'running', 'Checking'],
        ['check-updates', 'done', 'Done: latest version is 2.0.0'],
        ['check-fleet-versions', 'running', 'Checking'],
        ['check-fleet-versions', 'done', 'Done: 1 outdated node found'],
    );

    $fleetDonePayload = checkStepsFleetDonePayload($run);

    expect($fleetDonePayload['update_targets'] ?? null)->toBe(['gateway', 'local', 'agent-1', 'app-dev-1']);

    $keys = array_map(fn (array $step): string => $step[0], $steps);
    $checkUpdatesIndex = array_search('check-updates', $keys, true);
    $checkFleetIndex = array_search('check-fleet-versions', $keys, true);
    $gatewayIndex = array_search('gateway', $keys, true);

    expect($checkUpdatesIndex)->toBeLessThan($checkFleetIndex)
        ->and($checkFleetIndex)->toBeLessThan($gatewayIndex);
});

it('reports all nodes current when the gateway and every workload node match the target', function (): void {
    config()->set('app.version', '2.0.0');

    app()->instance(RemoteShell::class, new CheckStepsFakeShell(versions: [
        'agent-1' => '2.0.0',
    ]));
    app()->instance(UpdateRunnerPipeline::class, new CheckStepsNoopPipeline);

    $run = checkStepsRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);

    app(OperationUpdatePlanStore::class)->create($run, checkStepsSnapshot('2.0.0'));

    app(UpdateRunner::class)->run($run->id);

    expect(checkStepsEvents($run))->toContain(
        ['check-fleet-versions', 'done', 'Done: all nodes running on 2.0.0'],
    );

    expect(checkStepsFleetDonePayload($run))->not->toHaveKey('update_targets');
});

it('short-circuits when the fleet-version probe finds 0 outdated nodes', function (): void {
    config()->set('app.version', '2.0.0');

    $pipeline = new CheckStepsNoopPipeline;
    app()->instance(RemoteShell::class, new CheckStepsFakeShell(versions: [
        'agent-1' => '2.0.0',
    ]));
    app()->instance(UpdateRunnerPipeline::class, $pipeline);

    $run = checkStepsRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);

    app(OperationUpdatePlanStore::class)->create($run, checkStepsSnapshot('2.0.0'));

    app(UpdateRunner::class)->run($run->id);

    $keys = array_map(fn (array $step): string => $step[0], checkStepsEvents($run));

    expect($pipeline->gatewayUpdateCalled)->toBeFalse()
        ->and($pipeline->workloadsUpdateCalled)->toBeFalse()
        ->and($pipeline->fleetVerifyCalled)->toBeFalse()
        ->and($keys)->not->toContain('gateway')
        ->and($keys)->not->toContain('workload-nodes')
        ->and($keys)->not->toContain('verification');
});

it('emits check-fleet-versions running before any node version probe call', function (): void {
    config()->set('app.version', '2.0.0');

    $shell = new CheckStepsOrderingShell(versions: [
        'agent-1' => '1.0.0',
    ]);
    app()->instance(RemoteShell::class, $shell);
    app()->instance(UpdateRunnerPipeline::class, new CheckStepsNoopPipeline);

    $run = checkStepsRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);

    app(OperationUpdatePlanStore::class)->create($run, checkStepsSnapshot('2.0.0'));

    app(UpdateRunner::class)->run($run->id);

    expect($shell->probeStarted)->toBeTrue()
        ->and($shell->probeStartedAfterFleetCheckRunning)->toBeTrue();
});

it('clears the deferred start payload from the operation result when manifest resolution fails', function (): void {
    Http::fake([
        'github.com/*' => Http::response('unavailable', 503),
    ]);

    app()->instance(UpdateRunnerPipeline::class, new CheckStepsNoopPipeline);

    $run = app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
        result: ['update_start_request' => []],
    );

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(RuntimeException::class);

    $run->refresh();

    expect($run->status)->toBe(OperationStatus::Failed)
        ->and($run->result)->toBeNull()
        ->and($run->error)->toMatchArray([
            'code' => 'update_runner_failed',
        ]);
});

it('resolves the release manifest during check-updates when no plan was persisted at start', function (): void {
    config()->set('app.version', '2.0.0');

    $manifest = [
        'schema_version' => 1,
        'version' => '2.0.0',
        'source' => 'github-release',
        'images' => [
            'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway:2.0.0@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-linux-amd64',
                'sha256' => str_repeat('b', 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:2.0.0@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        ],
    ];

    Http::fake([
        'github.com/*' => Http::response($manifest, 200),
    ]);

    app()->instance(RemoteShell::class, new CheckStepsFakeShell(versions: [
        'agent-1' => '2.0.0',
    ]));
    app()->instance(UpdateRunnerPipeline::class, new CheckStepsNoopPipeline);

    $run = app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
        result: ['update_start_request' => []],
    );
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);

    app(UpdateRunner::class)->run($run->id);

    $plan = OperationUpdatePlan::query()->where('operation_run_id', $run->id)->first();

    expect($plan)->not->toBeNull()
        ->and($plan->target_version)->toBe('2.0.0')
        ->and(checkStepsEvents($run))->toContain(
            ['check-updates', 'running', 'Checking'],
            ['check-updates', 'done', 'Done: latest version is 2.0.0'],
            ['check-fleet-versions', 'running', 'Checking'],
        );

    Http::assertSentCount(1);
});

it('does not short-circuit when at least one node is outdated', function (): void {
    config()->set('app.version', '1.0.0');

    $pipeline = new CheckStepsNoopPipeline;
    app()->instance(RemoteShell::class, new CheckStepsFakeShell(versions: [
        'agent-1' => '2.0.0',
    ]));
    app()->instance(UpdateRunnerPipeline::class, $pipeline);

    $run = checkStepsRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);

    app(OperationUpdatePlanStore::class)->create($run, checkStepsSnapshot('2.0.0'));

    app(UpdateRunner::class)->run($run->id);

    $keys = array_map(fn (array $step): string => $step[0], checkStepsEvents($run));

    expect($pipeline->gatewayUpdateCalled)->toBeTrue()
        ->and($keys)->toContain('gateway');
});

function checkStepsRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

/**
 * @return list<array{0: string, 1: string, 2: string|null}>
 */
function checkStepsEvents(OperationRun $run): array
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

/**
 * @return array<string, mixed>
 */
function checkStepsFleetDonePayload(OperationRun $run): array
{
    $event = $run->events()
        ->where('event_type', 'step')
        ->get()
        ->first(fn (OperationEvent $event): bool => ($event->payload['key'] ?? null) === 'check-fleet-versions'
            && ($event->payload['status'] ?? null) === 'done');

    expect($event)->not->toBeNull();

    return $event->payload;
}

function checkStepsSnapshot(string $targetVersion): OperationUpdatePlanSnapshot
{
    $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:'.$targetVersion.'@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    $cliArtifacts = [
        'linux-amd64' => [
            'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v'.$targetVersion.'/orbit-linux-amd64',
            'sha256' => str_repeat('b', 64),
        ],
    ];
    $roleImages = [
        'orbit-caddy' => 'caddy:2-alpine',
        'orbit-websocket' => 'hardimpact/orbit-reverb:'.$targetVersion.'@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
    ];

    return new OperationUpdatePlanSnapshot(
        targetVersion: $targetVersion,
        gatewayImage: $gatewayImage,
        manifestSource: 'github-release',
        manifestVersion: $targetVersion,
        manifestSnapshot: [
            'version' => $targetVersion,
            'source' => 'github-release',
            'images' => ['gateway' => $gatewayImage],
            'cli_artifacts' => $cliArtifacts,
            'role_images' => $roleImages,
        ],
        cliArtifacts: $cliArtifacts,
        roleImages: $roleImages,
    );
}

final class CheckStepsNoopPipeline extends UpdateRunnerPipeline
{
    public bool $gatewayUpdateCalled = false;

    public bool $workloadsUpdateCalled = false;

    public bool $fleetVerifyCalled = false;

    public function __construct() {}

    #[Override]
    public function updateGateway(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->gatewayUpdateCalled = true;
    }

    #[Override]
    public function updateWorkloads(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->workloadsUpdateCalled = true;
    }

    #[Override]
    public function verifyFleet(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->fleetVerifyCalled = true;
    }
}

final class CheckStepsFakeShell implements RemoteShell
{
    /**
     * @param  array<string, string>  $versions
     */
    public function __construct(
        private array $versions = [],
    ) {}

    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        (new RemoteShellMetadata)->prologue($options['metadata'] ?? []);

        $version = $this->versions[$node->name] ?? '0.0.0';

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(['success' => ['data' => ['version' => $version]]], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        );
    }
}

final class CheckStepsOrderingShell implements RemoteShell
{
    public bool $probeStarted = false;

    public bool $probeStartedAfterFleetCheckRunning = false;

    /**
     * @param  array<string, string>  $versions
     */
    public function __construct(
        private array $versions = [],
    ) {}

    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if ($script === 'orbit --version --local --json') {
            $this->probeStarted = true;
            $this->probeStartedAfterFleetCheckRunning = OperationEvent::query()
                ->where('event_type', 'step')
                ->where('payload->key', 'check-fleet-versions')
                ->where('payload->status', 'running')
                ->exists();
        }

        $version = $this->versions[$node->name] ?? '0.0.0';

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(['success' => ['data' => ['version' => $version]]], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        );
    }
}
