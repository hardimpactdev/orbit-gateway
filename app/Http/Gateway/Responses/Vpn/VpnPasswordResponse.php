<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Vpn;

final readonly class VpnPasswordResponse
{
    /**
     * @param  array<string, mixed>  $vpn
     */
    public function __construct(
        public array $vpn,
    ) {}
}
