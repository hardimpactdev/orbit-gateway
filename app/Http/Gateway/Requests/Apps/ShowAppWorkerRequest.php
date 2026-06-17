<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Apps;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Apps\AppWorkerResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowAppWorkerRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $app,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/apps/'.rawurlencode($this->app).'/worker';
    }

    public function createDtoFromResponse(Response $response): AppWorkerResponse
    {
        return new AppWorkerResponse(data: $this->unwrapData($response));
    }
}
