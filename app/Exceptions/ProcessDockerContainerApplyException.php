<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class ProcessDockerContainerApplyException extends RuntimeException
{
    public function __construct(
        public readonly bool $hadExistingContainer,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
