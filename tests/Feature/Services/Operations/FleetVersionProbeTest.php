<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\StartsRemoteShellProcesses;
use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationRun;
use App\Services\Operations\FleetVersionProbe;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\RemoteShell\RemoteShellMetadata;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Support\Facades\Process;
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
        ->and($shell->scripts)->toBe(['orbit --version --local --json', 'orbit --version --local --json'])
        ->and($shell->calls[0]['options']['metadata'])->toBe(['ORBIT_OPERATION_ID' => $run->id]);
});

it('parses orbit --version --local --json success.data.version from each node', function (): void {
    config()->set('app.version', '2.0.0');

    $shell = new FleetVersionProbeFakeShell(versions: [
        'agent-1' => '1.0.0',
    ]);
    app()->instance(RemoteShell::class, $shell);

    $run = fleetVersionProbeRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->nodeVersions['agent-1'])->toBe('1.0.0')
        ->and($shell->scripts)->toBe(['orbit --version --local --json']);
});

it('treats malformed json, failed commands, and missing version fields as outdated', function (string $node, RemoteShellResult $result): void {
    config()->set('app.version', '2.0.0');

    app()->instance(RemoteShell::class, new FleetVersionProbeFakeShell(
        versions: ['agent-1' => '2.0.0'],
        failures: [$node => $result],
    ));

    $run = fleetVersionProbeRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->nodeVersions[$node])->toBeNull()
        ->and($report->outdatedCount)->toBe(1);
})->with([
    'failed command' => [
        'app-dev-1',
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'unreachable', durationMs: 5),
    ],
    'malformed json' => [
        'app-dev-1',
        new RemoteShellResult(exitCode: 0, stdout: 'not-json', stderr: '', durationMs: 5),
    ],
    'missing version field' => [
        'app-dev-1',
        new RemoteShellResult(exitCode: 0, stdout: json_encode(['success' => ['data' => []]], JSON_THROW_ON_ERROR), stderr: '', durationMs: 5),
    ],
]);

it('runs node version probes concurrently through RemoteShellPool while preserving stable result order', function (): void {
    config()->set('app.version', '2.0.0');
    config()->set('orbit.updates.fleet_version_probe_concurrency', 2);

    $shell = new FleetVersionProbeAsyncShell(versions: [
        'agent-1' => '1.0.0',
        'app-dev-1' => '2.0.0',
        'app-prod-1' => '2.0.0',
    ]);
    app()->instance(RemoteShell::class, $shell);

    $run = fleetVersionProbeRun();
    Node::factory()->agent()->create(['name' => 'agent-1', 'platform' => 'ubuntu_24-04']);
    Node::factory()->appDev()->create(['name' => 'app-dev-1', 'platform' => 'linux']);
    Node::factory()->appProd()->create(['name' => 'app-prod-1', 'platform' => 'linux']);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);

    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVersionProbeSnapshot('2.0.0'));

    $report = app(FleetVersionProbe::class)->probe($run, $plan);

    expect($report->nodeVersions)->toBe([
        'agent-1' => '1.0.0',
        'app-dev-1' => '2.0.0',
        'app-prod-1' => '2.0.0',
    ])
        ->and($shell->maxActiveProcesses)->toBeGreaterThan(1)
        ->and($shell->runCalls)->toBe(0)
        ->and($shell->scripts)->toBe([
            'orbit --version --local --json',
            'orbit --version --local --json',
            'orbit --version --local --json',
        ]);
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

        return new RemoteShellResult(
            exitCode: 0,
            stdout: fleetVersionProbeJsonStdout($version),
            stderr: '',
            durationMs: 5,
        );
    }
}

final class FleetVersionProbeAsyncShell implements RemoteShell, StartsRemoteShellProcesses
{
    /**
     * @var list<array{node: string, script: string, options: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @var list<string>
     */
    public array $scripts = [];

    public int $maxActiveProcesses = 0;

    public int $runCalls = 0;

    public int $activeProcesses = 0;

    /**
     * @param  array<string, string>  $versions
     */
    public function __construct(
        private array $versions = [],
    ) {}

    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runCalls++;
        $this->calls[] = [
            'node' => $node->name,
            'script' => $script,
            'options' => $options,
        ];
        $this->scripts[] = $script;

        $version = $this->versions[$node->name] ?? '0.0.0';

        return new RemoteShellResult(
            exitCode: 0,
            stdout: fleetVersionProbeJsonStdout($version),
            stderr: '',
            durationMs: 1,
        );
    }

    #[Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        $this->calls[] = [
            'node' => $node->name,
            'script' => $script,
            'options' => $options,
        ];
        $this->scripts[] = $script;

        $version = $this->versions[$node->name] ?? '0.0.0';
        $this->activeProcesses++;
        $this->maxActiveProcesses = max($this->maxActiveProcesses, $this->activeProcesses);

        return new FleetVersionProbeTrackingInvokedProcess(
            new FakeInvokedProcess(
                command: $script,
                process: Process::describe()
                    ->output(fleetVersionProbeJsonStdout($version))
                    ->exitCode(0),
            ),
            function (): void {
                $this->activeProcesses--;
            },
        );
    }
}

final class FleetVersionProbeTrackingInvokedProcess implements InvokedProcess
{
    private bool $finished = false;

    public function __construct(
        private readonly InvokedProcess $process,
        private readonly Closure $onFinished,
    ) {}

    public function id(): ?int
    {
        return $this->process->id();
    }

    public function command(): string
    {
        return $this->process->command();
    }

    public function signal(int $signal): static
    {
        $this->process->signal($signal);

        return $this;
    }

    public function running(): bool
    {
        return $this->process->running();
    }

    public function output(): string
    {
        return $this->process->output();
    }

    public function errorOutput(): string
    {
        return $this->process->errorOutput();
    }

    public function latestOutput(): string
    {
        return $this->process->latestOutput();
    }

    public function latestErrorOutput(): string
    {
        return $this->process->latestErrorOutput();
    }

    public function wait(?callable $output = null): ProcessResult
    {
        $result = $this->process->wait($output);
        $this->markFinished();

        return $result;
    }

    public function waitUntil(?callable $output = null): ProcessResult
    {
        $result = $this->process->waitUntil($output);
        $this->markFinished();

        return $result;
    }

    private function markFinished(): void
    {
        if ($this->finished) {
            return;
        }

        ($this->onFinished)();
        $this->finished = true;
    }
}

function fleetVersionProbeJsonStdout(string $version): string
{
    return json_encode([
        'success' => [
            'data' => [
                'version' => $version,
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}
