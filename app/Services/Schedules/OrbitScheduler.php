<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Data\Schedules\SchedulerTickResult;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\SchedulerState;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class OrbitScheduler
{
    public function __construct(
        private ScheduleDispatcher $dispatcher,
        private ScheduleInterval $interval,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function tick(?CarbonImmutable $now = null): SchedulerTickResult
    {
        $startedAt = $now ?? CarbonImmutable::now();

        $gatewayNode = $this->gatewayNode();

        $this->recordHeartbeat($gatewayNode, $startedAt);

        $dueSchedules = $this->dueSchedules($startedAt);
        $claimedSchedules = [];

        foreach ($dueSchedules as $schedule) {
            if (! $this->claimLock($gatewayNode, $schedule, $startedAt)) {
                continue;
            }

            $claimedSchedules[] = $schedule;
        }

        try {
            $dispatchResults = $this->dispatcher->runMany($claimedSchedules);
        } finally {
            foreach ($claimedSchedules as $schedule) {
                $this->releaseLock($gatewayNode, $schedule);
            }
        }

        return new SchedulerTickResult(
            startedAt: $startedAt,
            finishedAt: CarbonImmutable::now(),
            dueSchedules: count($dueSchedules),
            executedSchedules: count($dispatchResults),
        );
    }

    public function secondsUntilNextMinute(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $seconds = (int) $now->format('s');

        if ($seconds === 0) {
            return 60;
        }

        return 60 - $seconds;
    }

    private function gatewayNode(): Node
    {
        $gatewayNode = $this->nodeRoleAssignments
            ->activeGatewayNodeQuery()
            ->first();

        if ($gatewayNode instanceof Node) {
            return $gatewayNode;
        }

        throw new RuntimeException('Orbit Scheduler can only run when an active gateway node is registered.');
    }

    /**
     * @return list<Schedule>
     */
    private function dueSchedules(CarbonImmutable $now): array
    {
        return Schedule::query()
            ->with(['app.node', 'node'])
            ->where('enabled', true)
            ->where('status', 'expected')
            ->get()
            ->filter(fn (Schedule $schedule): bool => $this->interval->isDue($schedule->interval, $schedule->timezone, $now))
            ->values()
            ->all();
    }

    private function claimLock(Node $gatewayNode, Schedule $schedule, CarbonImmutable $now): bool
    {
        try {
            ScheduleLock::query()->create([
                'node_id' => $gatewayNode->id,
                'schedule_key' => $schedule->schedule_key,
                'owner_token' => $this->ownerToken($schedule, $now),
                'locked_at' => $now,
                'expires_at' => $now->addMinutes(15),
            ]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    private function releaseLock(Node $gatewayNode, Schedule $schedule): void
    {
        ScheduleLock::query()
            ->where('node_id', $gatewayNode->id)
            ->where('schedule_key', $schedule->schedule_key)
            ->delete();
    }

    private function recordHeartbeat(Node $gatewayNode, CarbonImmutable $heartbeatAt): void
    {
        SchedulerState::query()->updateOrCreate(
            ['node_id' => $gatewayNode->id],
            [
                'heartbeat_at' => $heartbeatAt,
                'registry_synced_at' => $heartbeatAt,
            ],
        );
    }

    private function ownerToken(Schedule $schedule, CarbonImmutable $now): string
    {
        return hash('sha256', $schedule->schedule_key.'|'.$now->toIso8601String());
    }
}
