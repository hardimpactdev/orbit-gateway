<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Activity;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Activity\ActivityListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListActivityRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $app = null,
        public readonly ?string $node = null,
        public readonly ?string $effect = null,
        public readonly ?string $correlation = null,
        public readonly ?int $limit = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/activity';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'node' => $this->node,
            'effect' => $this->effect,
            'correlation' => $this->correlation,
            'limit' => $this->limit,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function createDtoFromResponse(Response $response): ActivityListResponse
    {
        $data = $this->unwrapData($response);
        $activities = $data['activities'] ?? [];

        return new ActivityListResponse(
            activities: is_array($activities) ? array_values($activities) : [],
            meta: $this->envelopeMeta($response),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function envelopeMeta(Response $response): array
    {
        $body = json_decode($response->body(), true);

        if (! is_array($body)) {
            return [];
        }

        $success = $body['success'] ?? [];

        if (! is_array($success)) {
            return [];
        }

        $meta = $success['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }
}
