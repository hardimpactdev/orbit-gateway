<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\SchedulerState;
use App\Models\ScheduleRun;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use App\Services\Schedules\SchedulesProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

function scheduleProbeIssue(array $drift, string $key): mixed
{
    return collect($drift)->first(fn ($entry): bool => $entry->key === $key);
}

function fakeSchedulerSwarmService(?string $image = null, ?string $replicas = null): void
{
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => $image === null
            ? Process::result(exitCode: 1, errorOutput: "no such service: orbit_orbit-scheduler\n")
            : Process::result(output: "{$image}\n"),
        "docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'" => $replicas === null
            ? Process::result(exitCode: 1, errorOutput: "no such service: orbit_orbit-scheduler\n")
            : Process::result(output: "{$replicas}\n"),
    ]);
}

function createSchedulesProbeGatewayNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'status' => 'active',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    return $node;
}

describe('SchedulesProbe', function (): void {
    it('has key and label', function (): void {
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell));

        expect($probe->key())->toBe('schedule')
            ->and($probe->label())->toBe('Schedules');
    });

    it('detects incomplete schedule records', function (): void {
        $schedule = Schedule::factory()->create([
            'scope' => 'app',
            'app_id' => null,
            'target_name' => '',
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects invalid app targets', function (): void {
        $schedule = Schedule::factory()->create([
            'scope' => 'app',
            'app_id' => null,
            'target_name' => 'missing-app',
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.target_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('accepts active gateway role assignments as schedule targets', function (): void {
        $node = createSchedulesProbeGatewayNode();
        $schedule = Schedule::factory()->forNode($node)->create();
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.target_invalid'))->toBeNull();
    });

    it('detects unavailable gateway scheduler Swarm service', function (): void {
        $gateway = createSchedulesProbeGatewayNode(['name' => 'gateway-1']);
        $shell = new SchedulesProbeRemoteShell(stdout: "running=true\nrestart_policy=unless-stopped\nscheduler_running=true\n");
        $probe = new SchedulesProbe(new RuntimeBackendProbe($shell));
        fakeSchedulerSwarmService();

        $snapshot = $probe->introspectGateway($gateway);
        $drift = $probe->diffGateway($gateway, $snapshot);

        expect($shell->scripts)->toBe([])
            ->and(scheduleProbeIssue($drift, 'schedule.runtime_backend_unavailable')?->kind)->toBe(DriftKind::Missing)
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_missing'))->toBeNull();

        Process::assertRan("docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'");
    });

    it('detects missing scheduler configuration in the gateway Swarm service', function (): void {
        $gateway = createSchedulesProbeGatewayNode();
        $shell = new SchedulesProbeRemoteShell(stdout: "running=true\nrestart_policy=unless-stopped\nscheduler_running=true\n");
        $probe = new SchedulesProbe(new RuntimeBackendProbe($shell));
        fakeSchedulerSwarmService(
            image: 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            replicas: '0/0',
        );

        $snapshot = $probe->introspectGateway($gateway);
        $drift = $probe->diffGateway($gateway, $snapshot);

        expect($shell->scripts)->toBe([])
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_missing')?->kind)->toBe(DriftKind::Missing)
            ->and($snapshot->get('gateway'))->toMatchArray([
                'scheduler_service' => 'orbit_orbit-scheduler',
                'scheduler_status' => 'missing',
            ]);
    });

    it('detects stopped scheduler Swarm service replicas on the gateway', function (): void {
        $gateway = createSchedulesProbeGatewayNode();
        $shell = new SchedulesProbeRemoteShell(stdout: "running=true\nrestart_policy=unless-stopped\nscheduler_running=true\n");
        $probe = new SchedulesProbe(new RuntimeBackendProbe($shell));
        fakeSchedulerSwarmService(
            image: 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            replicas: '0/1',
        );

        $snapshot = $probe->introspectGateway($gateway);
        $drift = $probe->diffGateway($gateway, $snapshot);

        expect($shell->scripts)->toBe([])
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_stopped')?->kind)->toBe(DriftKind::Divergent)
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_stopped')?->detail)->toHaveKey('observed_status', 'stopped');
    });

    it('reads running scheduler state from the Swarm service instead of a runtime-container process', function (): void {
        $gateway = createSchedulesProbeGatewayNode();
        $shell = new SchedulesProbeRemoteShell(stdout: "running=true\nrestart_policy=unless-stopped\nscheduler_running=false\n");
        $probe = new SchedulesProbe(new RuntimeBackendProbe($shell));
        fakeSchedulerSwarmService(
            image: 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            replicas: '1/1',
        );

        $snapshot = $probe->introspectGateway($gateway);
        $drift = $probe->diffGateway($gateway, $snapshot);

        expect($shell->scripts)->toBe([])
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_missing'))->toBeNull()
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_stopped'))->toBeNull()
            ->and($snapshot->get('gateway'))->toMatchArray([
                'scheduler_service' => 'orbit_orbit-scheduler',
                'scheduler_image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'scheduler_status' => 'running',
            ]);
    });

    it('detects scheduler Swarm service image drift from the configured gateway image', function (): void {
        $gateway = createSchedulesProbeGatewayNode();
        SchedulerState::factory()->create([
            'node_id' => $gateway->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);
        config()->set('orbit.updates.gateway_image', 'ghcr.io/hardimpactdev/orbit-gateway:1.2.4@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell));
        fakeSchedulerSwarmService(
            image: 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            replicas: '1/1',
        );

        $snapshot = $probe->introspectGateway($gateway);
        $drift = $probe->diffGateway($gateway, $snapshot);

        expect(scheduleProbeIssue($drift, 'schedule.scheduler_image_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_image_mismatch')?->detail)->toMatchArray([
                'observed_image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'expected_image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.4@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            ]);
    });

    it('detects scheduler Swarm service replica drift when it is not a singleton service', function (): void {
        $gateway = createSchedulesProbeGatewayNode();
        SchedulerState::factory()->create([
            'node_id' => $gateway->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell));
        fakeSchedulerSwarmService(
            image: 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            replicas: '2/2',
        );

        $snapshot = $probe->introspectGateway($gateway);
        $drift = $probe->diffGateway($gateway, $snapshot);

        expect(scheduleProbeIssue($drift, 'schedule.scheduler_replicas_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_replicas_mismatch')?->detail)->toHaveKey('observed_replicas', '2/2')
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_stopped'))->toBeNull();
    });

    it('detects stale gateway heartbeat', function (): void {
        $gateway = createSchedulesProbeGatewayNode();
        SchedulerState::factory()->create([
            'node_id' => $gateway->id,
            'heartbeat_at' => now()->subMinutes(20),
            'registry_synced_at' => now()->subMinutes(20),
        ]);
        $shell = new SchedulesProbeRemoteShell(stdout: "running=true\nrestart_policy=unless-stopped\nscheduler_running=true\n");
        $probe = new SchedulesProbe(new RuntimeBackendProbe($shell));
        fakeSchedulerSwarmService(
            image: 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            replicas: '1/1',
        );

        $snapshot = $probe->introspectGateway($gateway);
        $drift = $probe->diffGateway($gateway, $snapshot);

        expect($shell->scripts)->toBe([])
            ->and(scheduleProbeIssue($drift, 'schedule.heartbeat_stale')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects stuck gateway schedule locks', function (): void {
        $gateway = createSchedulesProbeGatewayNode();
        SchedulerState::factory()->create([
            'node_id' => $gateway->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);
        ScheduleLock::factory()->create([
            'node_id' => $gateway->id,
            'schedule_key' => 'app:docs:laravel-scheduler',
            'locked_at' => now()->subMinutes(30),
            'expires_at' => now()->subMinutes(20),
        ]);
        $shell = new SchedulesProbeRemoteShell(stdout: "running=true\nrestart_policy=unless-stopped\nscheduler_running=true\n");
        $probe = new SchedulesProbe(new RuntimeBackendProbe($shell));
        fakeSchedulerSwarmService(
            image: 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            replicas: '1/1',
        );

        $snapshot = $probe->introspectGateway($gateway);
        $drift = $probe->diffGateway($gateway, $snapshot);

        expect($shell->scripts)->toBe([])
            ->and(scheduleProbeIssue($drift, 'schedule.lock_stuck')?->kind)->toBe(DriftKind::Divergent);
    });

    it('detects unreachable schedule targets from the gateway', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell(exitCode: 255, stderr: 'ssh timeout')));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.target_unreachable')?->kind)->toBe(DriftKind::Missing)
            ->and(scheduleProbeIssue($drift, 'schedule.scheduler_missing'))->toBeNull();
    });

    it('detects stuck schedule run history', function (): void {
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        ScheduleRun::factory()->create([
            'node_id' => $node->id,
            'schedule_key' => $schedule->schedule_key,
            'status' => 'running',
            'started_at' => now()->subMinutes(30),
            'finished_at' => null,
        ]);
        $probe = new SchedulesProbe(new RuntimeBackendProbe(new SchedulesProbeRemoteShell));

        $drift = $probe->diff($schedule, $probe->introspect($schedule));

        expect(scheduleProbeIssue($drift, 'schedule.run_stuck')?->kind)->toBe(DriftKind::Divergent);
    });
});

final class SchedulesProbeRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function __construct(
        private int $exitCode = 0,
        private string $stdout = "running\n",
        private string $stderr = '',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $this->stdout, stderr: $this->stderr, durationMs: 1);
    }
}

final class SchedulesProbeQueuedRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
