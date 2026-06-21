<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Proxy;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Proxy\ProxyRouteMutationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveProxyRouteRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $domain,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/proxy-routes/'.rawurlencode($this->domain);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ];
    }

    public function createDtoFromResponse(Response $response): ProxyRouteMutationResponse
    {
        return new ProxyRouteMutationResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
