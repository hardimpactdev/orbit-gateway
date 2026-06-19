<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Exceptions\UpdateLeaseConflict;
use App\Models\OperationRun;
use App\Models\UpdateLease;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UpdateLeaseManager
{
    private const array RESOURCE_TYPES = ['fleet', 'gateway', 'scheduler', 'node'];

    public function acquire(
        string $resourceType,
        string $resourceKey,
        OperationRun|string $operationRun,
        string $ownerToken,
        int $ttlSeconds,
    ): UpdateLease {
        $resourceType = trim($resourceType);
        $resourceKey = trim($resourceKey);
        $ownerToken = trim($ownerToken);
        $operationRunId = $operationRun instanceof OperationRun ? $operationRun->id : trim($operationRun);

        $this->assertResourceType($resourceType);
        $this->assertNonEmpty('resource key', $resourceKey);
        $this->assertNonEmpty('owner token', $ownerToken);
        $this->assertNonEmpty('operation run id', $operationRunId);

        if ($ttlSeconds < 1) {
            throw new RuntimeException('Update lease TTL must be positive.');
        }

        $now = Carbon::now();
        $expiresAt = $now->copy()->addSeconds($ttlSeconds);
        $activeResourceKey = $this->activeResourceKey($resourceType, $resourceKey);

        return DB::transaction(function () use (
            $resourceType,
            $resourceKey,
            $operationRunId,
            $ownerToken,
            $now,
            $expiresAt,
            $activeResourceKey,
        ): UpdateLease {
            $active = $this->activeLease($activeResourceKey);

            if ($active instanceof UpdateLease && $active->expires_at->gt($now)) {
                throw UpdateLeaseConflict::fromLease($active);
            }

            if ($active instanceof UpdateLease) {
                $this->deactivate($active, $now);
            }

            return $this->attemptCreate(
                resourceType: $resourceType,
                resourceKey: $resourceKey,
                activeResourceKey: $activeResourceKey,
                operationRunId: $operationRunId,
                ownerToken: $ownerToken,
                expiresAt: $expiresAt,
                now: $now,
            );
        });
    }

    public function release(UpdateLease|int $lease, string $ownerToken): UpdateLease
    {
        $ownerToken = trim($ownerToken);
        $this->assertNonEmpty('owner token', $ownerToken);

        return DB::transaction(function () use ($lease, $ownerToken): UpdateLease {
            $active = $this->leaseForUpdate($lease);

            if ($active->active_resource_key === null || $active->released_at !== null) {
                return $active;
            }

            if ($active->owner_token !== $ownerToken) {
                throw new RuntimeException('Update lease owner token mismatch.');
            }

            $this->deactivate($active, Carbon::now());

            return $active->refresh();
        });
    }

    public function heartbeat(UpdateLease|int $lease, string $ownerToken, int $ttlSeconds): UpdateLease
    {
        $ownerToken = trim($ownerToken);
        $this->assertNonEmpty('owner token', $ownerToken);

        if ($ttlSeconds < 1) {
            throw new RuntimeException('Update lease TTL must be positive.');
        }

        $expired = false;

        $heartbeat = DB::transaction(function () use ($lease, $ownerToken, $ttlSeconds, &$expired): UpdateLease {
            $active = $this->leaseForUpdate($lease);

            if ($active->active_resource_key === null || $active->released_at !== null) {
                throw new RuntimeException('Update lease is not active.');
            }

            if ($active->owner_token !== $ownerToken) {
                throw new RuntimeException('Update lease owner token mismatch.');
            }

            $now = Carbon::now();

            if ($active->expires_at->lte($now)) {
                $this->deactivate($active, $now);
                $expired = true;

                return $active->refresh();
            }

            $active->forceFill([
                'expires_at' => $now->copy()->addSeconds($ttlSeconds),
            ])->save();

            return $active->refresh();
        });

        if ($expired) {
            throw new RuntimeException('Update lease expired before heartbeat.');
        }

        return $heartbeat;
    }

    public function withLease(
        string $resourceType,
        string $resourceKey,
        OperationRun|string $operationRun,
        string $ownerToken,
        int $ttlSeconds,
        callable $callback,
    ): mixed {
        $lease = $this->acquire(
            resourceType: $resourceType,
            resourceKey: $resourceKey,
            operationRun: $operationRun,
            ownerToken: $ownerToken,
            ttlSeconds: $ttlSeconds,
        );

        try {
            return $callback($lease);
        } finally {
            $this->release($lease->id, $ownerToken);
        }
    }

    protected function beforeActiveLeaseCreate(string $activeResourceKey, string $resourceType, string $resourceKey): void
    {
        //
    }

    private function attemptCreate(
        string $resourceType,
        string $resourceKey,
        string $activeResourceKey,
        string $operationRunId,
        string $ownerToken,
        Carbon $expiresAt,
        Carbon $now,
    ): UpdateLease {
        $this->beforeActiveLeaseCreate($activeResourceKey, $resourceType, $resourceKey);

        try {
            return $this->createActiveLease(
                resourceType: $resourceType,
                resourceKey: $resourceKey,
                activeResourceKey: $activeResourceKey,
                operationRunId: $operationRunId,
                ownerToken: $ownerToken,
                expiresAt: $expiresAt,
            );
        } catch (QueryException $exception) {
            if (! $this->causedByUniqueConstraint($exception)) {
                throw $exception;
            }

            $active = $this->activeLease($activeResourceKey);

            if (! $active instanceof UpdateLease) {
                throw $exception;
            }

            if ($active->expires_at->lte($now)) {
                $this->deactivate($active, $now);

                return $this->createActiveLease(
                    resourceType: $resourceType,
                    resourceKey: $resourceKey,
                    activeResourceKey: $activeResourceKey,
                    operationRunId: $operationRunId,
                    ownerToken: $ownerToken,
                    expiresAt: $expiresAt,
                );
            }

            throw UpdateLeaseConflict::fromLease($active);
        }
    }

    private function createActiveLease(
        string $resourceType,
        string $resourceKey,
        string $activeResourceKey,
        string $operationRunId,
        string $ownerToken,
        Carbon $expiresAt,
    ): UpdateLease {
        /** @var UpdateLease $lease */
        $lease = UpdateLease::query()->create([
            'resource_type' => $resourceType,
            'resource_key' => $resourceKey,
            'active_resource_key' => $activeResourceKey,
            'operation_run_id' => $operationRunId,
            'owner_token' => $ownerToken,
            'expires_at' => $expiresAt,
        ]);

        return $lease;
    }

    private function activeLease(string $activeResourceKey): ?UpdateLease
    {
        /** @var UpdateLease|null $lease */
        $lease = UpdateLease::query()
            ->where('active_resource_key', $activeResourceKey)
            ->lockForUpdate()
            ->first();

        return $lease;
    }

    private function leaseForUpdate(UpdateLease|int $lease): UpdateLease
    {
        $id = $lease instanceof UpdateLease ? $lease->id : $lease;

        /** @var UpdateLease|null $active */
        $active = UpdateLease::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->first();

        if (! $active instanceof UpdateLease) {
            throw new RuntimeException("Update lease [{$id}] not found.");
        }

        return $active;
    }

    private function deactivate(UpdateLease $lease, Carbon $releasedAt): void
    {
        $lease->forceFill([
            'active_resource_key' => null,
            'released_at' => $lease->released_at ?? $releasedAt,
        ])->save();
    }

    private function activeResourceKey(string $resourceType, string $resourceKey): string
    {
        return "{$resourceType}:{$resourceKey}";
    }

    private function assertResourceType(string $resourceType): void
    {
        if (! in_array($resourceType, self::RESOURCE_TYPES, true)) {
            throw new RuntimeException('Update lease resource type must be one of fleet, gateway, scheduler, node.');
        }
    }

    private function assertNonEmpty(string $label, string $value): void
    {
        if ($value === '') {
            throw new RuntimeException("Update lease {$label} cannot be empty.");
        }
    }

    private function causedByUniqueConstraint(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['19', '1062'], true)
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');
    }
}
