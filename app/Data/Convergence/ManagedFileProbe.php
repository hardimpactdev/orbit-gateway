<?php

declare(strict_types=1);

namespace App\Data\Convergence;

final readonly class ManagedFileProbe
{
    public function __construct(
        public bool $reachable,
        public bool $exists,
        public ?string $hash = null,
        public ?string $mode = null,
        public ?string $error = null,
    ) {}
}
