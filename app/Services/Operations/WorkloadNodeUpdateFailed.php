<?php

declare(strict_types=1);

namespace App\Services\Operations;

use RuntimeException;

final class WorkloadNodeUpdateFailed extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $targetResults
     * @param  list<array<string, mixed>>  $failedTargets
     */
    public function __construct(
        public readonly array $targetResults,
        public readonly array $failedTargets,
    ) {
        parent::__construct('One or more workload nodes failed to update.');
    }
}
