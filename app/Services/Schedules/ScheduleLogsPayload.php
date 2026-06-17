<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleRun;
use Illuminate\Database\Eloquent\Builder;

final readonly class ScheduleLogsPayload
{
    public function __construct(
        private SchedulePayload $schedules,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array{lines: int, truncated: bool}}
     */
    public function forSchedule(string $name, ?string $app, ?string $node, ?int $runId, int $lines, ?Node $caller = null): array
    {
        $schedule = $this->schedules->find($name, $app, $node, $caller);
        $run = $this->resolveRun($schedule, $runId);
        [$stdout, $stdoutTruncated] = $this->limitLines($run->stdout ?? '', $lines);
        [$stderr, $stderrTruncated] = $this->limitLines($run->stderr ?? '', $lines);
        $targetNode = $schedule->scope === 'app' ? $schedule->app?->node : $schedule->node;

        return [
            'data' => [
                'run' => [
                    'id' => $run->id,
                    'schedule' => $schedule->name,
                    'scope' => $schedule->scope,
                    'target' => [
                        'type' => $schedule->scope,
                        'name' => $schedule->target_name,
                        'node' => $targetNode?->name,
                    ],
                    'status' => $run->status,
                    'exit_code' => $run->exit_code,
                    'started_at' => $run->started_at->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                ],
                'output' => [
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ],
            ],
            'meta' => [
                'lines' => $lines,
                'truncated' => $stdoutTruncated || $stderrTruncated,
            ],
        ];
    }

    private function resolveRun(Schedule $schedule, ?int $runId): ScheduleRun
    {
        $query = ScheduleRun::query()
            ->where('schedule_key', $schedule->schedule_key)
            ->when($runId !== null, fn (Builder $query): Builder => $query->where('id', $runId))
            ->latest('started_at')
            ->latest('id');

        $run = $query->first();

        if (! $run instanceof ScheduleRun) {
            throw new GatewayApiException(
                $runId === null
                    ? "No runs were found for schedule '{$schedule->name}'."
                    : "Run '{$runId}' was not found for schedule '{$schedule->name}'.",
                'schedule.run_not_found',
                [
                    'name' => $schedule->name,
                    'run' => $runId,
                ],
            );
        }

        return $run;
    }

    /**
     * @return array{string, bool}
     */
    private function limitLines(string $output, int $lines): array
    {
        if ($output === '') {
            return ['', false];
        }

        $parts = preg_split('/\R/', rtrim($output, "\r\n"));

        if ($parts === false || $parts === []) {
            return [$output, false];
        }

        if (count($parts) <= $lines) {
            return [$output, false];
        }

        return [implode(PHP_EOL, array_slice($parts, -$lines)).PHP_EOL, true];
    }
}
