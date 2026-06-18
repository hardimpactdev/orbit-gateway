<?php

declare(strict_types=1);

namespace App\Data\Vpn;

final readonly class VpnClientMutationResult
{
    public function __construct(
        public VpnClient $client,
        public string $action,
        public bool $alreadyInDesiredState = false,
    ) {}
}
