<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Processes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Processes\ProcessRestartResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RestartProcessesRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly ?string $app,
        public readonly ?string $workspace,
        public readonly ?string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/processes/restart';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'app' => $this->app,
            'workspace' => $this->workspace,
            'name' => $this->name,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): ProcessRestartResponse
    {
        return new ProcessRestartResponse(
            data: $this->unwrapData($response),
        );
    }
}
