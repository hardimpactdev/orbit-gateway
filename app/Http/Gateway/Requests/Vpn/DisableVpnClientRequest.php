<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Vpn;

final class DisableVpnClientRequest extends EnableVpnClientRequest
{
    #[\Override]
    public function resolveEndpoint(): string
    {
        return "/api/vpn/clients/{$this->name}/disable";
    }
}
