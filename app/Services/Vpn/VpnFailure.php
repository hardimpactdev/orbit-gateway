<?php

declare(strict_types=1);

namespace App\Services\Vpn;

final readonly class VpnFailure
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $meta = [],
    ) {}
}
