<?php

declare(strict_types=1);

namespace App\Services\Operations;

use RuntimeException;

class FleetUpdateVerificationFailed extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly string $publicMessage,
    ) {
        parent::__construct($publicMessage);
    }
}
