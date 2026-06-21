<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\UpdateLease;
use Illuminate\Support\Carbon;
use RuntimeException;

final class UpdateLeaseConflict extends RuntimeException
{
    public function __construct(
        public readonly string $resourceType,
        public readonly string $resourceKey,
        public readonly int $leaseId,
        public readonly string $operationRunId,
        public readonly string $ownerToken,
        public readonly Carbon $expiresAt,
    ) {
        parent::__construct(
            "Update resource [{$this->resourceType}:{$this->resourceKey}] is already leased by operation [{$this->operationRunId}] until {$this->expiresAt->toIso8601String()}."
        );
    }

    public static function fromLease(UpdateLease $lease): self
    {
        return new self(
            resourceType: $lease->resource_type,
            resourceKey: $lease->resource_key,
            leaseId: $lease->id,
            operationRunId: $lease->operation_run_id,
            ownerToken: $lease->owner_token,
            expiresAt: $lease->expires_at,
        );
    }

    /**
     * @return array{resource_type: string, resource_key: string, lease_id: int, operation_run_id: string, owner_token: string, expires_at: string}
     */
    public function context(): array
    {
        return [
            'resource_type' => $this->resourceType,
            'resource_key' => $this->resourceKey,
            'lease_id' => $this->leaseId,
            'operation_run_id' => $this->operationRunId,
            'owner_token' => $this->ownerToken,
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }
}
