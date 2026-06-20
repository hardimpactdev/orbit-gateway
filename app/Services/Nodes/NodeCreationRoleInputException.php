<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use InvalidArgumentException;

final class NodeCreationRoleInputException extends InvalidArgumentException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $meta,
    ) {
        parent::__construct($message);
    }
}
