<?php

declare(strict_types=1);

namespace App\Data\Convergence;

use App\Enums\Convergence\ConvergenceStatus;
use App\Enums\Processes\ProcessDockerContainerApplyOutcome;

final readonly class ProcessDockerContainerPlan
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public ConvergenceStatus $status,
        public string $summary,
        public ProcessDockerContainerApplyOutcome $outcome,
        public array $details = [],
    ) {}

    public function shouldApply(): bool
    {
        return $this->status === ConvergenceStatus::Changed;
    }
}
