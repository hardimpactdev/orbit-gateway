<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Enums\DriftKind;

final readonly class UpdatePostureIssue
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public string $code,
        public DriftKind $kind,
        public string $summary,
        public bool $restorable,
        public array $detail = [],
    ) {}
}
