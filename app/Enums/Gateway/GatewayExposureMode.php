<?php

declare(strict_types=1);

namespace App\Enums\Gateway;

use InvalidArgumentException;

enum GatewayExposureMode: string
{
    case RouterColocated = 'router-colocated';
    case GatewayDirect = 'gateway-direct';

    public static function parse(string $value): self
    {
        $value = trim($value);

        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Unsupported gateway exposure mode [{$value}]. Expected router-colocated or gateway-direct.");
    }

    public function isRouterColocated(): bool
    {
        return $this === self::RouterColocated;
    }

    public function isGatewayDirect(): bool
    {
        return $this === self::GatewayDirect;
    }

    public function publishesGatewayPort(): bool
    {
        return $this === self::GatewayDirect;
    }
}
