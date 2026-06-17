<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleRun;
use App\Services\Schedules\ScheduleDispatcher;

final readonly class RunSchedule
{
    public function __construct(
        private ScheduleDispatcher $dispatcher,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function handle(Schedule $schedule): array
    {
        $result = $this->dispatcher->run($schedule);
        $run = $result->run;
        $targetNode = $result->targetNode;

        $data = [
            'run' => $this->serializeRun($schedule, $run, $targetNode),
            'output' => [
                'stdout' => $run->stdout ?? '',
                'stderr' => $run->stderr ?? '',
            ],
        ];
        $meta = ['duration_ms' => $result->durationMs];

        if (! $result->successful()) {
            throw new GatewayApiException(
                "Schedule '{$schedule->name}' exited with status {$run->exit_code}.",
                'schedule.run_failed',
                $meta,
                errorData: $data,
            );
        }

        return [
            'data' => $data,
            'meta' => $meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(Schedule $schedule, ScheduleRun $run, Node $targetNode): array
    {
        return [
            'id' => $run->id,
            'schedule' => $schedule->name,
            'scope' => $schedule->scope,
            'target' => [
                'type' => $schedule->scope,
                'name' => $schedule->target_name,
                'node' => $targetNode->name,
            ],
            'status' => $run->status,
            'exit_code' => $run->exit_code,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
