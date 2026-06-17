<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Database;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Database\DatabaseOperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class SchemaDatabaseConnectionRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $operation,
        public readonly string $target,
        public readonly ?string $connection = null,
        public readonly ?string $table = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return match ($this->operation) {
            'tables' => '/api/database-connections/tables',
            'schema' => '/api/database-connections/schema',
            'describe' => '/api/database-connections/describe',
            default => '/api/database-connections/schema',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'target' => $this->target,
            'connection' => $this->connection,
            'table' => $this->table,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): DatabaseOperationResponse
    {
        return new DatabaseOperationResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
