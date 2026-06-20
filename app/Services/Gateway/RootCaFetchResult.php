<?php

declare(strict_types=1);

namespace App\Services\Gateway;

readonly class RootCaFetchResult
{
    public function __construct(
        public string $pem,
        public string $sha256,
        public string $sourceUrl,
    ) {}
}
