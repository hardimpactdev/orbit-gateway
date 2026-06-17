<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Tools;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Tools\ToolShowResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowToolRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $tool,
        public readonly ?string $app = null,
        public readonly ?string $node = null,
        public readonly bool $live = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/tools/'.rawurlencode($this->tool);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'node' => $this->node,
            'live' => $this->live ? '1' : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): ToolShowResponse
    {
        $data = $this->unwrapData($response);
        $tool = $data['tool'] ?? [];

        return new ToolShowResponse(
            tool: is_array($tool) ? $tool : [],
        );
    }
}
