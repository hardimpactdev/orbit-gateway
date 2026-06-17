<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Gateway;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Gateway\GatewayIdentityResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowGatewayIdentityRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/me';
    }

    #[\Override]
    public function hasRequestFailed(Response $response): ?bool
    {
        return false;
    }

    public function createDtoFromResponse(Response $response): GatewayIdentityResponse
    {
        $data = $this->unwrapData($response);
        $self = is_array($data['self'] ?? null) ? $data['self'] : ($data['node'] ?? null);
        $gateway = is_array($data['gateway'] ?? null) ? $data['gateway'] : null;

        return new GatewayIdentityResponse(
            self: is_array($self) ? $self : null,
            gateway: $gateway,
        );
    }
}
