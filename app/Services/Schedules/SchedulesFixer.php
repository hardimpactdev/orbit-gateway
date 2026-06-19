<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\ScheduleRun;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmManager;
use App\Services\Gateway\GatewaySwarmStackRenderer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use RuntimeException;

final readonly class SchedulesFixer
{
    private const string Stack = 'orbit';

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments = new NodeRoleAssignments,
        private GatewaySwarmManager $swarm = new GatewaySwarmManager,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(Schedule $schedule, DriftEntry $entry): ?array
    {
        $gatewayNode = $this->gatewayNode();

        return $gatewayNode instanceof Node
            ? $this->fixGateway($gatewayNode, $entry, $schedule)
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fixGateway(Node $gatewayNode, DriftEntry $entry, ?Schedule $schedule = null): ?array
    {
        if ($entry->key === 'schedule.lock_stuck') {
            $this->releaseStuckLock($gatewayNode, $entry, $schedule);

            return $this->action($gatewayNode, $entry, $schedule);
        }

        if ($entry->key === 'schedule.scheduler_image_mismatch') {
            $this->restoreGatewaySchedulerImage($entry);

            return $this->action($gatewayNode, $entry, $schedule);
        }

        if (! in_array($entry->key, ['schedule.scheduler_missing', 'schedule.scheduler_stopped', 'schedule.scheduler_replicas_mismatch'], true)) {
            return null;
        }

        $this->restoreGatewayScheduler();

        return $this->action($gatewayNode, $entry, $schedule);
    }

    private function restoreGatewayScheduler(): void
    {
        $service = $this->schedulerStackService();

        if ($this->swarm->serviceImage($service) === null) {
            throw new RuntimeException("Gateway scheduler Swarm service [{$service}] is missing.");
        }

        $this->swarm->scaleService($service, 1);
    }

    private function restoreGatewaySchedulerImage(DriftEntry $entry): void
    {
        $service = $this->schedulerStackService();
        $expectedImage = is_string($entry->detail['expected_image'] ?? null)
            ? $entry->detail['expected_image']
            : config('orbit.updates.gateway_image');

        if (! is_string($expectedImage) || trim($expectedImage) === '') {
            throw new RuntimeException('Configured gateway image is unavailable for scheduler image repair.');
        }

        $this->swarm->updateServiceImage($service, GatewayImageReference::fromString($expectedImage), 'stop-first');
        $this->swarm->scaleService($service, 1);
    }

    private function releaseStuckLock(Node $gatewayNode, DriftEntry $entry, ?Schedule $schedule): void
    {
        $scheduleKey = is_string($entry->detail['schedule_key'] ?? null)
            ? $entry->detail['schedule_key']
            : $schedule?->schedule_key;

        $query = ScheduleLock::query()->where('node_id', $gatewayNode->id);

        if ($scheduleKey !== null) {
            $query->where('schedule_key', $scheduleKey);
        }

        $query->delete();

        if ($scheduleKey === null) {
            return;
        }

        $runningRun = ScheduleRun::query()
            ->where('schedule_key', $scheduleKey)
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if (! $runningRun instanceof ScheduleRun) {
            return;
        }

        $runningRun->forceFill([
            'status' => 'failed',
            'exit_code' => $runningRun->exit_code ?? 1,
            'stderr' => trim((string) $runningRun->stderr."\nSchedule lock was released by doctor restore."),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function action(Node $gatewayNode, DriftEntry $entry, ?Schedule $schedule): array
    {
        return [
            'family' => 'schedule',
            'node' => $gatewayNode->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => $schedule instanceof Schedule
                ? "Repaired Orbit Scheduler for schedule {$schedule->name}."
                : 'Repaired gateway Orbit Scheduler.',
            'details' => array_filter([
                'schedule' => $schedule?->name,
                ...($entry->detail ?? []),
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    private function gatewayNode(): ?Node
    {
        return $this->nodeRoleAssignments
            ->activeGatewayNodeQuery()
            ->first();
    }

    private function schedulerStackService(): string
    {
        return self::Stack.'_'.GatewaySwarmStackRenderer::SchedulerService;
    }
}
