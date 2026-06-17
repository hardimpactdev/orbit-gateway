<?php

declare(strict_types=1);

namespace App\Data\Schedules;

use Carbon\CarbonImmutable;

final readonly class SchedulerTickResult
{
    public function __construct(
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
        public int $dueSchedules,
        public int $executedSchedules,
    ) {}
}
