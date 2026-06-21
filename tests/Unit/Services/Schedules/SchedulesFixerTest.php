<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\ScheduleRun;
use App\Services\Schedules\SchedulesFixer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

function createSchedulesFixerGatewayNode(): Node
{
    $node = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $node;
}

describe('SchedulesFixer', function (): void {
    it('scales the gateway orbit-scheduler Swarm service when scheduler configuration is missing', function (): void {
        $gateway = createSchedulesFixerGatewayNode();
        $shell = new SchedulesFixerRemoteShell;
        Process::fake([
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n"),
            "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
        ]);

        $action = (new SchedulesFixer)->fixGateway($gateway, new DriftEntry(
            family: 'schedule',
            key: 'schedule.scheduler_missing',
            kind: DriftKind::Missing,
            summary: 'Orbit Scheduler program is missing.',
        ));

        expect($action)->toMatchArray([
            'family' => 'schedule',
            'node' => 'gateway-1',
            'key' => 'schedule.scheduler_missing',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and($shell->scripts)->toBe([]);

        Process::assertRan("docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'");
        Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
        Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'orbit'.'-runtime'));
    });

    it('scales the gateway orbit-scheduler Swarm service when scheduler is stopped', function (): void {
        $gateway = createSchedulesFixerGatewayNode();
        $shell = new SchedulesFixerRemoteShell;
        Process::fake([
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n"),
            "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
        ]);

        $action = (new SchedulesFixer)->fixGateway($gateway, new DriftEntry(
            family: 'schedule',
            key: 'schedule.scheduler_stopped',
            kind: DriftKind::Divergent,
            summary: 'Orbit Scheduler program is not running.',
        ));

        expect($action)->toMatchArray([
            'family' => 'schedule',
            'node' => 'gateway-1',
            'key' => 'schedule.scheduler_stopped',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and($shell->scripts)->toBe([]);

        Process::assertRan("docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'");
        Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
        Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'orbit'.'-runtime'));
    });

    it('updates and scales the gateway orbit-scheduler Swarm service when the image drifts', function (): void {
        $gateway = createSchedulesFixerGatewayNode();
        $shell = new SchedulesFixerRemoteShell;
        $desiredImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.4@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        config()->set('orbit.updates.gateway_image', $desiredImage);
        Process::fake([
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n"),
            "docker service update --detach=true --image '{$desiredImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" => Process::result(),
            "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
        ]);

        $action = (new SchedulesFixer)->fixGateway($gateway, new DriftEntry(
            family: 'schedule',
            key: 'schedule.scheduler_image_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Orbit Scheduler service image does not match the configured gateway image.',
            detail: ['expected_image' => $desiredImage],
        ));

        expect($action)->toMatchArray([
            'family' => 'schedule',
            'node' => 'gateway-1',
            'key' => 'schedule.scheduler_image_mismatch',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and($shell->scripts)->toBe([]);

        Process::assertRan("docker service update --detach=true --image '{$desiredImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'");
        Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
    });

    it('scales the gateway orbit-scheduler Swarm service back to one replica when replica state drifts', function (): void {
        $gateway = createSchedulesFixerGatewayNode();
        $shell = new SchedulesFixerRemoteShell;
        Process::fake([
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n"),
            "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
        ]);

        $action = (new SchedulesFixer)->fixGateway($gateway, new DriftEntry(
            family: 'schedule',
            key: 'schedule.scheduler_replicas_mismatch',
            kind: DriftKind::Divergent,
            summary: 'Orbit Scheduler service replica count is not singleton.',
            detail: ['observed_replicas' => '2/2'],
        ));

        expect($action)->toMatchArray([
            'family' => 'schedule',
            'node' => 'gateway-1',
            'key' => 'schedule.scheduler_replicas_mismatch',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and($shell->scripts)->toBe([]);

        Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
    });

    it('releases stale gateway schedule locks and marks running history failed', function (): void {
        $gateway = createSchedulesFixerGatewayNode();
        $node = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['node_id' => $node->id]);
        $schedule = Schedule::factory()->forApp($app)->create();
        ScheduleLock::factory()->create([
            'node_id' => $gateway->id,
            'schedule_key' => $schedule->schedule_key,
            'locked_at' => now()->subMinutes(30),
            'expires_at' => now()->subMinutes(20),
        ]);
        ScheduleRun::factory()->create([
            'node_id' => $node->id,
            'schedule_key' => $schedule->schedule_key,
            'status' => 'running',
            'exit_code' => null,
            'finished_at' => null,
        ]);
        $shell = new SchedulesFixerRemoteShell;

        $action = (new SchedulesFixer)->fixGateway($gateway, new DriftEntry(
            family: 'schedule',
            key: 'schedule.lock_stuck',
            kind: DriftKind::Divergent,
            summary: 'Schedule has a stale execution lock.',
            detail: ['schedule_key' => $schedule->schedule_key],
        ), $schedule);

        $run = ScheduleRun::query()->where('schedule_key', $schedule->schedule_key)->firstOrFail();

        expect($action)->toMatchArray([
            'family' => 'schedule',
            'node' => 'gateway-1',
            'key' => 'schedule.lock_stuck',
            'mode' => 'fix',
            'status' => 'completed',
        ])->and(ScheduleLock::query()->where('schedule_key', $schedule->schedule_key)->exists())->toBeFalse()
            ->and($run->status)->toBe('failed')
            ->and($run->stderr)->toContain('Schedule lock was released by doctor restore.')
            ->and($shell->scripts)->toBe([]);
    });
});

final class SchedulesFixerRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
