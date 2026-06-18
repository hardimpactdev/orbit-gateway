<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\StartsRemoteShellProcesses;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\SchedulerState;
use App\Models\ScheduleRun;
use App\Services\Schedules\OrbitScheduler;
use App\Services\Schedules\ScheduleInterval;
use App\Services\Schedules\SchedulesFixer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('runs one scheduler daemon tick on demand', function (): void {
    createOrbitSchedulerGatewayNode();

    $this->artisan('orbit-scheduler --once')
        ->expectsOutputToContain('Orbit Scheduler tick completed')
        ->assertSuccessful();
});

it('starts the scheduler through the gateway image scheduler entrypoint', function (): void {
    $entrypoint = file_get_contents(repo_path('docker/orbit-gateway/entrypoint.sh'));

    expect($entrypoint)
        ->toContain('scheduler)')
        ->toContain('run_artisan orbit-'.'scheduler "$@"')
        ->not->toContain('php "$artisan" serve')
        ->not->toContain('PHP_CLI_SERVER_WORKERS');
});

it('renders scheduler repair through the gateway Swarm scheduler service instead of host supervisor', function (): void {
    $gateway = createOrbitSchedulerGatewayNode();
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n"),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
    ]);

    (new SchedulesFixer)->fixGateway($gateway, new DriftEntry(
        family: 'schedule',
        key: 'schedule.scheduler_stopped',
        kind: DriftKind::Divergent,
        summary: 'Orbit Scheduler service is stopped.',
    ));

    Process::assertRan("docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'");
    Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'orbit'.'-runtime'));
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'supervisor'));
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'docker restart'));
});

it('dispatches due app schedules from the gateway and records run history centrally', function (): void {
    $gateway = createOrbitSchedulerGatewayNode();
    $appNode = createOrbitSchedulerAppHostNode(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id, 'path' => '/srv/docs']);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
        'execution_value' => 'php artisan schedule:run',
        'interval' => 'every minute',
    ]);
    $remoteShell = new OrbitSchedulerRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: "ran\n", stderr: '', durationMs: 25),
    ]);
    app()->instance(RemoteShell::class, $remoteShell);

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    $run = ScheduleRun::query()->firstOrFail();
    $state = SchedulerState::query()->firstOrFail();

    expect($result->dueSchedules)->toBe(1)
        ->and($result->executedSchedules)->toBe(1)
        ->and($remoteShell->nodes)->toBe(['app-1'])
        ->and($remoteShell->scripts)->toBe(['php artisan schedule:run'])
        ->and($remoteShell->options[0]['timeout'])->toBe(900)
        ->and($remoteShell->options[0]['cwd'])->toBe('/srv/docs')
        ->and($run->node_id)->toBe($appNode->id)
        ->and($run->schedule_key)->toBe('app:docs:laravel-scheduler')
        ->and($run->status)->toBe('completed')
        ->and($run->stdout)->toBe("ran\n")
        ->and($state->node_id)->toBe($gateway->id)
        ->and($state->heartbeat_at?->toIso8601String())->toBe('2026-05-06T12:34:00+00:00')
        ->and(ScheduleLock::query()->count())->toBe(0);
});

it('runs gateway-target schedules locally without remote shell dispatch', function (): void {
    $gateway = createOrbitSchedulerGatewayNode();
    Schedule::factory()->orbit()->create([
        'name' => 'gateway-maintenance',
        'schedule_key' => 'orbit:gateway:gateway-maintenance',
        'execution_value' => 'php apps/gateway/artisan orbit:cleanup',
        'interval' => 'every minute',
    ]);
    $remoteShell = new OrbitSchedulerRecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);
    Process::fake([
        'php apps/gateway/artisan orbit:cleanup' => Process::result(output: "local\n"),
    ]);
    Process::preventStrayProcesses();

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    $run = ScheduleRun::query()->firstOrFail();

    expect($result->dueSchedules)->toBe(1)
        ->and($result->executedSchedules)->toBe(1)
        ->and($remoteShell->scripts)->toBe([])
        ->and($run->node_id)->toBe($gateway->id)
        ->and($run->status)->toBe('completed')
        ->and($run->stdout)->toBe("local\n");

    Process::assertRan('php apps/gateway/artisan orbit:cleanup');
});

it('dispatches multiple remote schedules through the remote shell pool', function (): void {
    createOrbitSchedulerGatewayNode();
    $firstNode = createOrbitSchedulerAppHostNode(['name' => 'app-1']);
    $secondNode = createOrbitSchedulerAppHostNode(['name' => 'app-2']);
    $thirdNode = createOrbitSchedulerAppHostNode(['name' => 'app-3']);

    foreach ([[$firstNode, 'one'], [$secondNode, 'two'], [$thirdNode, 'three']] as [$node, $name]) {
        $app = App::factory()->create([
            'name' => $name,
            'node_id' => $node->id,
            'path' => "/srv/{$name}",
        ]);

        Schedule::factory()->forApp($app)->create([
            'name' => 'scheduler',
            'schedule_key' => "app:{$name}:scheduler",
            'execution_value' => "echo {$name}",
            'interval' => 'every minute',
        ]);
    }

    $remoteShell = new OrbitSchedulerAsyncRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    expect($result->dueSchedules)->toBe(3)
        ->and($result->executedSchedules)->toBe(3)
        ->and($remoteShell->runCalls)->toBe(0)
        ->and($remoteShell->started)->toBe([
            ['node' => 'app-1', 'script' => 'echo one', 'cwd' => '/srv/one'],
            ['node' => 'app-2', 'script' => 'echo two', 'cwd' => '/srv/two'],
            ['node' => 'app-3', 'script' => 'echo three', 'cwd' => '/srv/three'],
        ])
        ->and($remoteShell->maxActiveProcesses)->toBe(3)
        ->and(ScheduleRun::query()->where('status', 'completed')->count())->toBe(3)
        ->and(ScheduleLock::query()->count())->toBe(0);
});

it('skips schedules that are not due', function (): void {
    createOrbitSchedulerGatewayNode();

    Schedule::factory()->orbit()->create([
        'name' => 'daily-report',
        'schedule_key' => 'orbit:gateway:daily-report',
        'interval' => 'daily at 09:00',
    ]);

    $remoteShell = new OrbitSchedulerRecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    expect($result->dueSchedules)->toBe(0)
        ->and($result->executedSchedules)->toBe(0)
        ->and($remoteShell->scripts)->toBe([])
        ->and(ScheduleRun::query()->count())->toBe(0);
});

it('records remote dispatch failures as failed gateway history', function (): void {
    createOrbitSchedulerGatewayNode();
    $appNode = createOrbitSchedulerAppHostNode(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
        'interval' => 'every minute',
    ]);
    app()->instance(RemoteShell::class, new OrbitSchedulerRecordingRemoteShell(throwable: new RuntimeException('ssh timeout')));

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));
    $run = ScheduleRun::query()->firstOrFail();

    expect($result->dueSchedules)->toBe(1)
        ->and($result->executedSchedules)->toBe(1)
        ->and($run->node_id)->toBe($appNode->id)
        ->and($run->status)->toBe('failed')
        ->and($run->exit_code)->toBe(1)
        ->and($run->stderr)->toBe('ssh timeout')
        ->and(ScheduleLock::query()->count())->toBe(0);
});

it('evaluates portable schedule interval expressions', function (): void {
    $interval = new ScheduleInterval;
    $now = CarbonImmutable::parse('2026-05-06T12:35:00Z');

    expect($interval->isDue('every minute', 'UTC', $now))->toBeTrue()
        ->and($interval->isDue('every 5 minutes', 'UTC', $now))->toBeTrue()
        ->and($interval->isDue('every 10 minutes', 'UTC', $now))->toBeFalse()
        ->and($interval->isDue('daily at 14:35', 'Europe/Amsterdam', $now))->toBeTrue()
        ->and($interval->isDue('weekdays at 14:35', 'Europe/Amsterdam', $now))->toBeTrue()
        ->and($interval->isDue('weekly on wednesday at 14:35', 'Europe/Amsterdam', $now))->toBeTrue()
        ->and($interval->isDue('weekly on monday at 14:35', 'Europe/Amsterdam', $now))->toBeFalse();
});

function createOrbitSchedulerGatewayNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => 'gateway-1',
        'host' => '10.6.0.10',
        'wireguard_address' => '10.6.0.10',
        'status' => 'active',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $node;
}

function createOrbitSchedulerAppHostNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'status' => 'active',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

final class OrbitSchedulerRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $nodes = [];

    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results = [],
        private ?RuntimeException $throwable = null,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if ($this->throwable instanceof RuntimeException) {
            throw $this->throwable;
        }

        $this->nodes[] = $node->name;
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class OrbitSchedulerAsyncRemoteShell implements RemoteShell, StartsRemoteShellProcesses
{
    public int $runCalls = 0;

    public int $activeProcesses = 0;

    public int $maxActiveProcesses = 0;

    /**
     * @var list<array{node: string, script: string, cwd: string|null}>
     */
    public array $started = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runCalls++;

        throw new RuntimeException('Synchronous remote shell should not be used for pooled scheduler dispatch.');
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        $this->started[] = [
            'node' => $node->name,
            'script' => $script,
            'cwd' => is_string($options['cwd'] ?? null) ? $options['cwd'] : null,
        ];
        $this->activeProcesses++;
        $this->maxActiveProcesses = max($this->maxActiveProcesses, $this->activeProcesses);

        return new OrbitSchedulerTrackingInvokedProcess(
            new FakeInvokedProcess(
                command: $script,
                process: Process::describe()
                    ->output("ran {$node->name}")
                    ->exitCode(0),
            ),
            function (): void {
                $this->activeProcesses--;
            },
        );
    }
}

final class OrbitSchedulerTrackingInvokedProcess implements InvokedProcess
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
