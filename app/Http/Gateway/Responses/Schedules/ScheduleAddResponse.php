<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Schedules;

final readonly class ScheduleAddResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}
}
