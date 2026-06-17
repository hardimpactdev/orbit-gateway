<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Gateway;

final readonly class GatewayIdentityResponse
{
    /**
     * @param  array<string, mixed>|null  $self
     * @param  array<string, mixed>|null  $gateway
     */
    public function __construct(
        public ?array $self,
        public ?array $gateway,
    ) {}
}
