<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Activity;

final readonly class ActivityShowResponse
{
    /**
     * @param  array<string, mixed>  $activity
     * @param  list<array<string, mixed>>  $related
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $activity,
        public array $related,
        public array $meta,
    ) {}
}
