<?php

declare(strict_types=1);

namespace App\Data\Convergence;

use App\Enums\Convergence\ConvergenceStatus;

final readonly class ConvergenceApplyResult
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public ConvergenceStatus $status,
        public string $summary,
        public array $details = [],
    ) {}

    public function successful(): bool
    {
        return in_array($this->status, [ConvergenceStatus::Ok, ConvergenceStatus::Changed], true);
    }

    public function changed(): bool
    {
        return $this->status === ConvergenceStatus::Changed;
    }
}
