<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Apps;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Apps\AppShowResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowAppRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $app,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/apps/'.rawurlencode($this->app);
    }

    public function createDtoFromResponse(Response $response): AppShowResponse
    {
        $data = $this->unwrapData($response);
        $app = $data['app'] ?? [];
        $details = $data['details'] ?? [];

        return new AppShowResponse(
            app: is_array($app) ? $app : [],
            details: is_array($details) ? $details : [],
        );
    }
}
