<?php

declare(strict_types=1);

namespace App\Services\Security;

final readonly class InstallReport
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public bool $successful,
        public string $summary,
        public array $details = [],
    ) {}
}
