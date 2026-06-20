<?php

declare(strict_types=1);

namespace App\Data\WireGuard;

final readonly class WireGuardPeerReality
{
    /**
     * @param  list<string>  $allowedIps
     * @param  list<string>  $allowedAddresses
     */
    public function __construct(
        public string $publicKey,
        public array $allowedIps,
        public array $allowedAddresses,
    ) {}
}
