<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Gateway;

use App\Http\Gateway\GatewayRequest;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowGatewayCaRootRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/ca/root';
    }

    #[\Override]
    public function hasRequestFailed(Response $response): ?bool
    {
        return false;
    }
}
