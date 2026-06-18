<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Database;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Database\DatabaseConnectionResultResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveDatabaseConnectionRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $connection,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/database-connections/'.rawurlencode($this->connection);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return ['force' => true];
    }

    public function createDtoFromResponse(Response $response): DatabaseConnectionResultResponse
    {
        $data = $this->unwrapData($response);

        return new DatabaseConnectionResultResponse(
            result: is_array($data['result'] ?? null) ? $data['result'] : [],
        );
    }
}
