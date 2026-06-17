<?php

declare(strict_types=1);

namespace App\Services\Apps;

use RuntimeException;
use Throwable;

final class AppRuntimeContainerApplyException extends RuntimeException
{
    public function __construct(
        public readonly bool $hadExistingContainer,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
