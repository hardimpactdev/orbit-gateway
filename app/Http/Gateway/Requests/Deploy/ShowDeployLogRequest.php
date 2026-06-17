<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Deploy;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Deploy\DeployResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowDeployLogRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $app,
        public readonly int $run,
        public readonly ?int $step = null,
        public readonly int $lines = 500,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/deploy/log/{$this->run}";
    }

    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'step' => $this->step,
            'lines' => $this->lines,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): DeployResponse
    {
        return DeployResponseFactory::fromResponse($response);
    }
}
