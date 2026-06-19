<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Php;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Php\PhpRuntimeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowPhpRuntimeRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $app = null,
        public readonly ?string $workspace = null,
        public readonly ?string $node = null,
        public readonly bool $live = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/php/runtime';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'workspace' => $this->workspace,
            'node' => $this->node,
            'live' => $this->live ? true : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): PhpRuntimeResponse
    {
        $data = $this->unwrapData($response);

        return new PhpRuntimeResponse(
            php: is_array($data['php'] ?? null) ? $data['php'] : [],
            meta: $this->unwrapMeta($response),
        );
    }
}
