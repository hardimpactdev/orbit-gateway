<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Vpn;

final readonly class VpnClientResponse
{
    /**
     * @param  array<string, mixed>  $client
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $client,
        public array $meta,
    ) {}
}
