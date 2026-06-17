<?php

declare(strict_types=1);

namespace App\Services\Operations;

/**
 * Per-operation-type recognition contract for typed results returned by an
 * internal command or a gateway workflow. The handler uses this contract to
 * validate that every key in the typed result is known for the operation
 * type before {@see ResultBoundaryRedactionPolicy} scans for secret material.
 */
interface OperationResultContract
{
    /**
     * The gateway-owned operation_type this contract handles (e.g.
     * `workspace.setup`, `tool.install`).
     */
    public function operationType(): string;

    /**
     * Allowed top-level result keys for this operation type. Unknown keys at
     * the top level fail closed with `operation.result_unrecognized`.
     *
     * @return list<string>
     */
    public function allowedKeys(): array;
}
