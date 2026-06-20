<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Apps;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Apps\AppListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListAppsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $node = null,
        public readonly ?string $environment = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/apps';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'node' => $this->node,
            'environment' => $this->environment,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): AppListResponse
    {
        $data = $this->unwrapData($response);
        $apps = $data['apps'] ?? [];

        return new AppListResponse(
            apps: is_array($apps) ? array_values($apps) : [],
        );
    }
}
