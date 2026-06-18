<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Enums\DriftKind;

final readonly class DriftEntry
{
    /**
     * @param  array<string, mixed>|null  $detail
     */
    public function __construct(
        public string $family,
        public string $key,
        public DriftKind $kind,
        public string $summary,
        public ?array $detail = null,
        public ?string $action = null,
    ) {}
}
