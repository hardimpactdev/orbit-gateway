<?php

declare(strict_types=1);

namespace App\Data\Vpn;

final readonly class VpnPasswordRotationResult
{
    public function __construct(
        public bool $passwordChanged,
        public bool $sessionsInvalidated,
    ) {}
}
