<?php

declare(strict_types=1);

namespace App\Data\Security;

final readonly class PinnedHostKey
{
    public function __construct(
        public string $host,
        public string $type,
        public string $publicKey,
        public string $fingerprint,
        public string $pinMode,
    ) {}
}
