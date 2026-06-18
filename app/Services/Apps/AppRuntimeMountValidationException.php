<?php

declare(strict_types=1);

namespace App\Services\Apps;

use RuntimeException;

final class AppRuntimeMountValidationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }
}
