<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use RuntimeException;
use Throwable;

final class WorkspaceRuntimeContainerApplyException extends RuntimeException
{
    public function __construct(
        public readonly bool $hadExistingContainer,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
