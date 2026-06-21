<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Database;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Database\DatabaseOperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class QueryDatabaseConnectionRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $target,
        public readonly string $sql,
        public readonly ?string $connection = null,
        public readonly array $options = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/database-connections/query';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'target' => $this->target,
            'connection' => $this->connection,
            'sql' => $this->sql,
            ...$this->options,
        ];
    }

    public function createDtoFromResponse(Response $response): DatabaseOperationResponse
    {
        return new DatabaseOperationResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
