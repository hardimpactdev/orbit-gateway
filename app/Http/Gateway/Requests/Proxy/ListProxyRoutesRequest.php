<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Proxy;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Proxy\ProxyRouteListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListProxyRoutesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $filter = null,
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/proxy-routes';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'filter' => $this->filter,
            'node' => $this->node,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): ProxyRouteListResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $routes = $data['routes'] ?? [];

        return new ProxyRouteListResponse(
            routes: is_array($routes) ? array_values($routes) : [],
            meta: $meta,
        );
    }
}
