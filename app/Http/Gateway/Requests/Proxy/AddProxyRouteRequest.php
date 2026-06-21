<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Proxy;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Proxy\ProxyRouteMutationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AddProxyRouteRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $domain,
        public readonly string $node,
        public readonly ?string $upstream,
        public readonly ?string $redirect,
        public readonly ?int $code,
        public readonly bool $force,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/proxy-routes';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'domain' => $this->domain,
            'node' => $this->node,
            'upstream' => $this->upstream,
            'redirect' => $this->redirect,
            'code' => $this->code,
            'force' => $this->force,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): ProxyRouteMutationResponse
    {
        return new ProxyRouteMutationResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
