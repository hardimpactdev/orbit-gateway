<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Database;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Database\DatabaseConnectionListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListDatabaseConnectionsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $app = null,
        public readonly ?string $workspace = null,
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/database-connections';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'workspace' => $this->workspace,
            'node' => $this->node,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): DatabaseConnectionListResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $connections = $data['connections'] ?? [];

        return new DatabaseConnectionListResponse(
            connections: is_array($connections) ? array_values($connections) : [],
            count: (int) ($meta['count'] ?? 0),
        );
    }
}
