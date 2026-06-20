<?php

declare(strict_types=1);

namespace App\Services\Operations;

use RuntimeException;

/**
 * Thrown when a typed operation result (or future framed progress payload)
 * fails the shared recognition/redaction policy and must not be persisted.
 */
final class OperationPayloadRejected extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }
}
