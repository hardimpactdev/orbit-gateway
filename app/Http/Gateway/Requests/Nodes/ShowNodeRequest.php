<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeShowResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowNodeRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/nodes/{$this->name}";
    }

    public function createDtoFromResponse(Response $response): NodeShowResponse
    {
        $data = $this->unwrapData($response);
        $node = $data['node'] ?? [];

        return new NodeShowResponse(
            node: is_array($node) ? $node : [],
        );
    }
}
