<?php

declare(strict_types=1);

namespace App\Services\Operations;

use RuntimeException;

/**
 * Registry of typed result contracts keyed by operation_type. Tests and DI
 * bootstrap can register contracts at runtime; the handler asks the registry
 * to look up the contract for an incoming result before validating keys.
 */
final class OperationResultRegistry
{
    /**
     * @var array<string, OperationResultContract>
     */
    private array $contracts = [];

    public function register(OperationResultContract $contract): void
    {
        $this->contracts[$contract->operationType()] = $contract;
    }

    public function has(string $operationType): bool
    {
        return array_key_exists($operationType, $this->contracts);
    }

    public function get(string $operationType): OperationResultContract
    {
        if (! $this->has($operationType)) {
            throw new RuntimeException("No OperationResultContract registered for operation_type '{$operationType}'.");
        }

        return $this->contracts[$operationType];
    }
}
