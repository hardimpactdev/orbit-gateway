<?php

declare(strict_types=1);

namespace App\Services\Updates;

final readonly class UpdatePostureSnapshot
{
    /**
     * @param  list<UpdatePostureIssue>  $issues
     */
    public function __construct(
        public string $driver,
        public array $issues,
    ) {}
}
