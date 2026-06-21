<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Schedules;

final readonly class ScheduleShowResponse
{
    /**
     * @param  array<string, mixed>  $schedule
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $schedule,
        public array $meta,
    ) {}
}
