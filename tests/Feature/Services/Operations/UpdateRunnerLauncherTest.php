<?php

declare(strict_types=1);

use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Models\OperationRun;
use App\Services\Gateway\GatewaySwarmManager;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdateRunnerLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();

    $this->configRoot = sys_get_temp_dir().'/orbit-update-runner-'.bin2hex(random_bytes(6));

    config()->set('orbit.paths.config_root', $this->configRoot);
    config()->set(
        'orbit.updates.gateway_image',
        'ghcr.io/hardimpactdev/orbit-gateway:current@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
    );
});

it('launches the one shot runner from the persisted digest pinned gateway image', function (): void {
    $run = updateRunnerLaunchRun();
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        updateRunnerLaunchSnapshot(
            targetVersion: '9.9.9',
            gatewayImage: 'ghcr.io/hardimpactdev/orbit-gateway:9.9.9@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
        ),
    );

    Process::fake([
        'docker run *' => Process::result(output: "runner\n"),
    ]);

    app(UpdateRunnerLauncher::class)->launch($run);

    Process::assertRan(function ($process) use ($plan, $run): bool {
        $command = (string) $process->command;
        $containerArguments = Str::after($command, "'{$plan->gateway_image}'");

        expect($command)
            ->toContain('docker run')
            ->toContain('--rm')
            ->toContain('--detach')
            ->toContain("--label 'orbit.operation_run_id={$run->id}'")
            ->toContain("--label 'orbit.role=update-runner'")
            ->toContain("--network 'orbit-network'")
            ->toContain("--mount 'type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock'")
            ->toContain("--mount 'type=bind,source={$this->configRoot},target=/home/orbit/.config/orbit'")
            ->toContain("--mount 'type=bind,source=/home/orbit/.ssh,target=/root/.ssh,readonly'")
            ->toContain("--env 'ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit'")
            ->toContain("'{$plan->gateway_image}'")
            ->toContain("'orbit:update-runner'")
            ->toContain("'--operation-run-id={$run->id}'")
            ->not->toContain('--target-image')
            ->not->toContain('docker service inspect');

        expect($containerArguments)
            ->toContain("'orbit:update-runner'")
            ->toContain("'--operation-run-id={$run->id}'")
            ->not->toContain('9.9.9')
            ->not->toContain('manifest')
            ->not->toContain('gateway-image')
            ->not->toContain('target-image');

        return true;
    });
});

it('launches the runner from the configured bootstrap gateway image when no plan exists yet', function (): void {
    $run = updateRunnerLaunchRun();

    Process::fake([
        'docker run *' => Process::result(output: "runner\n"),
    ]);

    app(UpdateRunnerLauncher::class)->launch($run);

    Process::assertRan(function ($process): bool {
        expect((string) $process->command)->toContain(
            'ghcr.io/hardimpactdev/orbit-gateway:current@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
        );

        return true;
    });
});

it('resolves the running gateway swarm service image when no plan or config override exists', function (): void {
    config()->set('orbit.updates.gateway_image', null);

    $run = updateRunnerLaunchRun();
    $liveImage = 'ghcr.io/hardimpactdev/orbit-gateway:0.1.146@sha256:acf5560fef69663643d2ddebd79532e64dc82131eb5751055406d0c223804797';

    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' '".GatewaySwarmManager::DeployedGatewayService."'" => Process::result(output: "{$liveImage}\n"),
        'docker run *' => Process::result(output: "runner\n"),
    ]);

    app(UpdateRunnerLauncher::class)->launch($run);

    Process::assertRan(function ($process) use ($liveImage): bool {
        $command = (string) $process->command;

        if (! str_starts_with($command, 'docker run')) {
            return false;
        }

        expect($command)->toContain("'{$liveImage}'");

        return true;
    });
});

it('rejects a non digest pinned running gateway service image fallback', function (): void {
    config()->set('orbit.updates.gateway_image', null);

    $run = updateRunnerLaunchRun();

    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' '".GatewaySwarmManager::DeployedGatewayService."'" => Process::result(output: "ghcr.io/hardimpactdev/orbit-gateway:0.1.146\n"),
    ]);

    expect(fn () => app(UpdateRunnerLauncher::class)->launch($run))
        ->toThrow(RuntimeException::class, 'Running gateway service image must be digest-pinned.');
});

it('fails loudly when neither config nor running gateway service image is available', function (): void {
    config()->set('orbit.updates.gateway_image', null);

    $run = updateRunnerLaunchRun();

    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' '".GatewaySwarmManager::DeployedGatewayService."'" => Process::result(exitCode: 1, errorOutput: "service not found\n"),
    ]);

    expect(fn () => app(UpdateRunnerLauncher::class)->launch($run))
        ->toThrow(RuntimeException::class, 'could not be resolved');
});

it('fails with a useful message when Docker cannot start the runner', function (): void {
    $run = updateRunnerLaunchRun();

    app(OperationUpdatePlanStore::class)->create($run, updateRunnerLaunchSnapshot());

    Process::fake([
        'docker run *' => Process::result(errorOutput: "denied\n", exitCode: 1),
    ]);

    expect(fn () => app(UpdateRunnerLauncher::class)->launch($run))
        ->toThrow(RuntimeException::class, 'Failed to launch update runner');
});

function updateRunnerLaunchRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
        result: [
            'status' => OperationStatus::Queued->value,
        ],
    );
}

/**
 * @param  array<string, mixed>  $manifestOverrides
 */
function updateRunnerLaunchSnapshot(
    string $targetVersion = '1.2.3',
    string $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    array $manifestOverrides = [],
): OperationUpdatePlanSnapshot {
    $manifest = array_replace_recursive([
        'version' => $targetVersion,
        'source' => 'github-release',
        'images' => [
            'gateway' => $gatewayImage,
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => "https://github.com/hardimpactdev/orbit/releases/download/v{$targetVersion}/orbit-linux-amd64",
                'sha256' => str_repeat('b', 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        ],
    ], $manifestOverrides);

    return new OperationUpdatePlanSnapshot(
        targetVersion: $targetVersion,
        gatewayImage: $gatewayImage,
        manifestSource: 'github-release',
        manifestVersion: $targetVersion,
        manifestSnapshot: $manifest,
        cliArtifacts: $manifest['cli_artifacts'],
        roleImages: $manifest['role_images'],
    );
}
