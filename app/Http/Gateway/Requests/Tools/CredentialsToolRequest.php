<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Tools;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Tools\ToolCredentialsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class CredentialsToolRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $tool,
        public readonly ?string $app = null,
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/tools/'.rawurlencode($this->tool).'/credentials';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'node' => $this->node,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): ToolCredentialsResponse
    {
        $data = $this->unwrapData($response);
        $credentials = $data['credentials'] ?? [];

        return new ToolCredentialsResponse(
            credentials: is_array($credentials) ? $credentials : [],
        );
    }
}
