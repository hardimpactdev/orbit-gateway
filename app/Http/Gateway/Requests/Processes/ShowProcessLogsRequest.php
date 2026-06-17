<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Processes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Processes\ProcessLogsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowProcessLogsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $name,
        public readonly ?string $app,
        public readonly ?string $workspace,
        public readonly int $lines = 100,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/processes/'.rawurlencode($this->name).'/log';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'workspace' => $this->workspace,
            'lines' => $this->lines,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): ProcessLogsResponse
    {
        $body = $response->json();
        $meta = [];

        if (is_array($body) && isset($body['success']['meta']) && is_array($body['success']['meta'])) {
            $meta = $body['success']['meta'];
        }

        return new ProcessLogsResponse(
            data: $this->unwrapData($response),
            meta: $meta,
        );
    }
}
