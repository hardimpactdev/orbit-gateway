<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Vpn;

final readonly class VpnClientListResponse
{
    /**
     * @param  list<array<string, mixed>>  $clients
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $clients,
        public array $meta,
    ) {}
}
