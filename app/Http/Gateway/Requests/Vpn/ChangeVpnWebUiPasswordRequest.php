<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Vpn;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Vpn\VpnPasswordResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ChangeVpnWebUiPasswordRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $password,
        public readonly bool $force = true,
        public readonly ?string $totp = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/vpn/web-ui/password';
    }

    protected function defaultBody(): array
    {
        return array_filter([
            'password' => $this->password,
            'force' => $this->force,
            'totp' => $this->totp,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): VpnPasswordResponse
    {
        $data = $this->unwrapData($response);

        return new VpnPasswordResponse(
            vpn: is_array($data['vpn'] ?? null) ? $data['vpn'] : [],
        );
    }
}
