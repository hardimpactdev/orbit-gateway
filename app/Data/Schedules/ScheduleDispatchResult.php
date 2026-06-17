<?php

declare(strict_types=1);

namespace App\Data\Schedules;

use App\Models\Node;
use App\Models\ScheduleRun;

final readonly class ScheduleDispatchResult
{
    public function __construct(
        public ScheduleRun $run,
        public Node $targetNode,
        public int $durationMs,
    ) {}

    public function successful(): bool
    {
        return $this->run->status === 'completed';
    }
}
