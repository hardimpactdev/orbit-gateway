<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Apps;

final readonly class AppListResponse
{
    /**
     * @param  list<array<string, mixed>>  $apps
     */
    public function __construct(
        public array $apps,
    ) {}
}
