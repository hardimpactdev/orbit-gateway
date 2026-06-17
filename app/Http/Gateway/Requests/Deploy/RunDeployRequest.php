<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Deploy;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Deploy\DeployResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RunDeployRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $app,
        public readonly bool $detach = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/deploy/run';
    }

    protected function defaultBody(): array
    {
        return [
            'app' => $this->app,
            'detach' => $this->detach,
        ];
    }

    public function createDtoFromResponse(Response $response): DeployResponse
    {
        return DeployResponseFactory::fromResponse($response);
    }
}
