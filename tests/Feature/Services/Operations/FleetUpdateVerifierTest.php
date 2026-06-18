<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationRun;
use App\Services\Operations\FleetUpdateVerificationFailed;
use App\Services\Operations\FleetUpdateVerifier;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdateRunner;
use App\Services\RemoteShell\RemoteShellMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

it('verifies gateway scheduler workload CLI and required role images', function (): void {
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n"),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n"),
    ]);

    $shell = new FleetVerifierFakeShell;
    app()->instance(RemoteShell::class, $shell);

    $run = fleetVerifierRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->database()->create(['name' => 'database-1', 'platform' => 'ubuntu']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);
    Node::factory()->ingress()->create(['name' => 'ingress-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->operator()->create(['name' => 'operator-1']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVerifierSnapshot());

    app(FleetUpdateVerifier::class)->verify($run, $plan);

    expect($shell->calls)->toHaveCount(6)
        ->and($shell->calls[0])->toMatchArray([
            'node' => 'agent-1',
            'script' => 'orbit --version',
        ])
        ->and($shell->calls[0]['options']['metadata'])->toBe(['ORBIT_OPERATION_ID' => $run->id])
        ->and($shell->calls[3])->toMatchArray([
            'node' => 'ingress-1',
            'script' => 'orbit --version',
        ])
        ->and($shell->calls[5]['options']['metadata'])->toBe(['ORBIT_OPERATION_ID' => $run->id])
        ->and(array_column($shell->calls, 'node'))->toBe([
            'agent-1',
            'app-dev-1',
            'database-1',
            'ingress-1',
            'app-dev-1',
            'ingress-1',
        ])
        ->and($shell->calls[4]['script'])->toContain("docker image inspect 'caddy:2-alpine' >/dev/null")
        ->and($shell->calls[5]['script'])->toContain("docker image inspect 'caddy:2-alpine' >/dev/null");
});

it('fails when workload CLI verification fails', function (): void {
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(output: "gateway-image\n"),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "scheduler-image\n"),
    ]);

    app()->instance(RemoteShell::class, new FleetVerifierFakeShell(failScriptsContaining: [
        'orbit --version' => new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'orbit missing', durationMs: 10),
    ]));

    $run = fleetVerifierRun();
    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVerifierSnapshot());
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);

    expect(fn () => app(FleetUpdateVerifier::class)->verify($run, $plan))
        ->toThrow(FleetUpdateVerificationFailed::class, 'CLI verification failed');
});

it('fails when a required role image is missing on a workload node', function (): void {
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(output: "gateway-image\n"),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "scheduler-image\n"),
    ]);

    app()->instance(RemoteShell::class, new FleetVerifierFakeShell(failScriptsContaining: [
        'docker image inspect' => new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'missing image', durationMs: 10),
    ]));

    $run = fleetVerifierRun();
    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVerifierSnapshot());
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);

    expect(fn () => app(FleetUpdateVerifier::class)->verify($run, $plan))
        ->toThrow(FleetUpdateVerificationFailed::class, 'Required role image verification failed');
});

it('emits terminal success only after runner verification passes', function (): void {
    app()->instance(RemoteShell::class, new FleetVerifierFakeShell);

    $run = fleetVerifierRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVerifierSnapshot(targetVersion: '1.2.3'));

    fakeFleetVerifierGatewayUpdateProcesses($plan->gateway_image);
    fakeFleetVerifierGatewayMigrations();

    app(UpdateRunner::class)->run($run->id);

    $run->refresh();

    expect($run->status)->toBe(OperationStatus::Succeeded)
        ->and(fleetVerifierStepEvents($run))->toBe([
            ['runner', 'running'],
            ['lease.fleet', 'done'],
            ['check-updates', 'running'],
            ['check-updates', 'done'],
            ['check-fleet-versions', 'running'],
            ['check-fleet-versions', 'done'],
            ['gateway', 'running'],
            ['lease.gateway', 'done'],
            ['scheduler.stop', 'running'],
            ['scheduler.stop', 'done'],
            ['migrations', 'running'],
            ['migrations', 'done'],
            ['gateway.service', 'running'],
            ['gateway.service', 'done'],
            ['scheduler.start', 'running'],
            ['scheduler.start', 'done'],
            ['gateway', 'done'],
            ['workload-nodes', 'running'],
            ['workload.app-dev-1', 'running'],
            ['workload.app-dev-1', 'running'],
            ['workload.app-dev-1', 'running'],
            ['workload.app-dev-1', 'running'],
            ['workload.app-dev-1', 'done'],
            ['workload-nodes', 'done'],
            ['verification', 'running'],
            ['verification.gateway', 'running'],
            ['verification.gateway', 'done'],
            ['verification.scheduler', 'running'],
            ['verification.scheduler', 'done'],
            ['verification.cli', 'running'],
            ['verification.cli', 'done'],
            ['verification.role-images', 'running'],
            ['verification.role-images', 'done'],
            ['verification', 'done'],
        ])
        ->and($run->events()->where('event_type', 'complete')->first()?->payload)->toMatchArray([
            'exit_code' => 0,
            'data' => [
                'target_version' => '1.2.3',
                'manifest_version' => '1.2.3',
                'status' => 'succeeded',
            ],
        ]);
});

it('emits terminal failure when runner verification fails', function (): void {
    app()->instance(RemoteShell::class, new FleetVerifierFakeShell(failScriptsContaining: [
        'orbit --version' => new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'orbit missing', durationMs: 10),
    ]));

    $run = fleetVerifierRun();
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVerifierSnapshot());

    fakeFleetVerifierGatewayUpdateProcesses($plan->gateway_image);
    fakeFleetVerifierGatewayMigrations();

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(FleetUpdateVerificationFailed::class);

    $run->refresh();

    expect($run->status)->toBe(OperationStatus::Failed)
        ->and($run->error)->toMatchArray([
            'code' => 'cli_verification_failed',
            'message' => 'CLI verification failed.',
        ])
        ->and(fleetVerifierStepEvents($run))->toContain(
            ['verification.cli', 'fail'],
            ['verification', 'fail'],
        )
        ->and($run->events()->where('event_type', 'error')->first()?->payload)->toMatchArray([
            'data' => [
                'code' => 'cli_verification_failed',
            ],
        ]);
});

function fleetVerifierRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

function fakeFleetVerifierGatewayUpdateProcesses(string $gatewayImage): void
{
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "{$gatewayImage}\n"),
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
        "docker service update --detach=true --image '{$gatewayImage}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'" => Process::result(),
        "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(output: "completed\n"),
        "docker service update --detach=true --image '{$gatewayImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" => Process::result(),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(output: "{$gatewayImage}\n"),
    ]);
}

function fakeFleetVerifierGatewayMigrations(): void
{
    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturn(0);
}

/**
 * @return list<array{0: string, 1: string}>
 */
function fleetVerifierStepEvents(OperationRun $run): array
{
    return $run->events()
        ->where('event_type', 'step')
        ->get()
        ->map(fn ($event): array => [$event->payload['key'], $event->payload['status']])
        ->all();
}

/**
 * @param  array<string, array{url: string, sha256: string}>  $cliArtifacts
 * @param  array<string, string>  $roleImages
 */
function fleetVerifierSnapshot(
    string $targetVersion = '1.2.3',
    array $cliArtifacts = [],
    array $roleImages = [],
): OperationUpdatePlanSnapshot {
    $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
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

final class FleetVerifierFakeShell implements RemoteShell
{
    /**
     * @var list<array{node: string, script: string, options: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @param  array<string, RemoteShellResult>  $failScriptsContaining
     */
    public function __construct(
        private array $failScriptsContaining = [],
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

        foreach ($this->failScriptsContaining as $needle => $result) {
            if (str_contains($script, $needle)) {
                return $result;
            }
        }

        return new RemoteShellResult(exitCode: 0, stdout: "ok\n", stderr: '', durationMs: 10);
    }
}
