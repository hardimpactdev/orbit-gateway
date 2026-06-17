<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Deploy;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Deploy\DeployResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AddDeployStepRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $app,
        public readonly string $command,
        public readonly ?string $title = null,
        public readonly ?int $order = null,
        public readonly int $timeout = 600,
        public readonly ?int $retention = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/deploy/steps';
    }

    protected function defaultBody(): array
    {
        return array_filter([
            'app' => $this->app,
            'command' => $this->command,
            'title' => $this->title,
            'order' => $this->order,
            'timeout' => $this->timeout,
            'retention' => $this->retention,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): DeployResponse
    {
        return DeployResponseFactory::fromResponse($response);
    }
}
