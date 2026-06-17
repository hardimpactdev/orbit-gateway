<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Vpn;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Vpn\VpnClientListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListVpnClientsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $totp = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/vpn/clients';
    }

    protected function defaultQuery(): array
    {
        return array_filter(['totp' => $this->totp]);
    }

    public function createDtoFromResponse(Response $response): VpnClientListResponse
    {
        $data = $this->unwrapData($response);

        return new VpnClientListResponse(
            clients: is_array($data['clients'] ?? null) ? array_values($data['clients']) : [],
            meta: $this->unwrapMeta($response),
        );
    }
}
