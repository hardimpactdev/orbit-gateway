<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Processes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Processes\ProcessListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListProcessesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $app = null,
        public readonly ?string $workspace = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/processes';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'workspace' => $this->workspace,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): ProcessListResponse
    {
        $data = $this->unwrapData($response);
        $context = $data['context'] ?? [];
        $processes = $data['processes'] ?? [];

        return new ProcessListResponse(
            context: is_array($context) ? $context : [],
            processes: is_array($processes) ? array_values($processes) : [],
        );
    }
}
