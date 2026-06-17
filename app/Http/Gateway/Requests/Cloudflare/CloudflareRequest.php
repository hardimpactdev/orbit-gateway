<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Cloudflare;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Cloudflare\CloudflareResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CloudflareRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $queryParameters
     */
    public function __construct(
        protected Method $method,
        private readonly string $endpoint,
        private readonly array $payload = [],
        private readonly array $queryParameters = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter($this->payload, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter($this->queryParameters, fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): CloudflareResponse
    {
        return new CloudflareResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
