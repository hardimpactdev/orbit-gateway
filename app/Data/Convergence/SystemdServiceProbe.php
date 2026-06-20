<?php

declare(strict_types=1);

namespace App\Data\Convergence;

final readonly class SystemdServiceProbe
{
    public function __construct(
        public bool $reachable,
        public bool $exists,
        public bool $enabled,
        public ?string $hash = null,
        public ?string $error = null,
    ) {}
}
