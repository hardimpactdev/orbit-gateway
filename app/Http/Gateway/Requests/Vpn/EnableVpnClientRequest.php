<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Vpn;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Vpn\VpnClientResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

class EnableVpnClientRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $name,
        public readonly ?string $totp = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/vpn/clients/{$this->name}/enable";
    }

    protected function defaultBody(): array
    {
        return array_filter(['totp' => $this->totp]);
    }

    public function createDtoFromResponse(Response $response): VpnClientResponse
    {
        $data = $this->unwrapData($response);

        return new VpnClientResponse(
            client: is_array($data['client'] ?? null) ? $data['client'] : [],
            meta: $this->unwrapMeta($response),
        );
    }
}
