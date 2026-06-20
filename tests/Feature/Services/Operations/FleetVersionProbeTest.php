<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationRun;
use App\Services\Operations\FleetVersionProbe;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\RemoteShell\RemoteShellMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('probes the gateway and each workload node version and counts outdated nodes', function (): void {
    config()->set('app.version', '2.0.0');

    $shell = new FleetVersionProbeFakeShell(versions: [
        'agent-1' => '1.0.0',
        'app-dev-1' => '2.0.0',
    ]);
    app()->instance(RemoteShell::class, $shell);

    $run = fleetVersionProbeRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);
    Node::factory()->operator()->create(['name' => 'operator-1']);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->targetVersion)->toBe('2.0.0')
        ->and($report->gatewayVersion)->toBe('2.0.0')
        ->and($report->nodeVersions)->toBe([
            'agent-1' => '1.0.0',
            'app-dev-1' => '2.0.0',
        ])
        ->and($report->outdatedCount)->toBe(1)
        ->and($report->allCurrent())->toBeFalse()
        ->and($shell->scripts)->toBe(['orbit --version', 'orbit --version'])
        ->and($shell->calls[0]['options']['metadata'])->toBe(['ORBIT_OPERATION_ID' => $run->id]);
});

it('counts the gateway as outdated when its baked version is behind the target', function (): void {
    config()->set('app.version', '1.0.0');

    app()->instance(RemoteShell::class, new FleetVersionProbeFakeShell(versions: [
        'agent-1' => '2.0.0',
    ]));

    $run = fleetVersionProbeRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->gatewayVersion)->toBe('1.0.0')
        ->and($report->outdatedCount)->toBe(1)
        ->and($report->allCurrent())->toBeFalse();
});

it('reports all nodes current when the gateway and every workload node match the target', function (): void {
    config()->set('app.version', '2.0.0');

    app()->instance(RemoteShell::class, new FleetVersionProbeFakeShell(versions: [
        'agent-1' => '2.0.0',
        'app-dev-1' => '2.0.0',
    ]));

    $run = fleetVersionProbeRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->outdatedCount)->toBe(0)
        ->and($report->allCurrent())->toBeTrue();
});

it('treats an unreadable node version as outdated so the node is still updated', function (): void {
    config()->set('app.version', '2.0.0');

    app()->instance(RemoteShell::class, new FleetVersionProbeFakeShell(
        versions: ['agent-1' => '2.0.0'],
        failures: ['app-dev-1' => new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'unreachable', durationMs: 5)],
    ));

    $run = fleetVersionProbeRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->nodeVersions)->toBe([
        'agent-1' => '2.0.0',
        'app-dev-1' => null,
    ])
        ->and($report->outdatedCount)->toBe(1);
});

function fleetVersionProbeRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

function fleetVersionProbeSnapshot(string $targetVersion): OperationUpdatePlanSnapshot
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

final class FleetVersionProbeFakeShell implements RemoteShell
{
    /**
     * @var list<array{node: string, script: string, options: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  array<string, string>  $versions
     * @param  array<string, RemoteShellResult>  $failures
     */
    public function __construct(
        private array $versions = [],
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
        $this->scripts[] = $script;

        if (isset($this->failures[$node->name])) {
            return $this->failures[$node->name];
        }

        $version = $this->versions[$node->name] ?? '0.0.0';

        return new RemoteShellResult(exitCode: 0, stdout: "Version       {$version}\nReleased at   18-06-2026\n", stderr: '', durationMs: 5);
    }
}
