<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Workspaces;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceHistoryResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowWorkspaceHistoryRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $app = null,
        public readonly ?string $path = null,
        public readonly ?int $limit = null,
        public readonly ?string $since = null,
        public readonly ?string $until = null,
    ) {}

    public function resolveEndpoint(): string
    {
        if ($this->name === null) {
            return '/api/workspaces/history/resolve-by-path';
        }

        return "/api/workspaces/{$this->name}/history";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'path' => $this->path,
            'limit' => $this->limit,
            'since' => $this->since,
            'until' => $this->until,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): WorkspaceHistoryResponse
    {
        $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        $success = is_array($body) ? ($body['success'] ?? []) : [];
        $data = is_array($success) ? ($success['data'] ?? []) : [];
        $meta = is_array($success) ? ($success['meta'] ?? []) : [];
        $runs = $data['runs'] ?? [];
        $pagination = is_array($meta) ? ($meta['pagination'] ?? []) : [];

        return new WorkspaceHistoryResponse(
            runs: is_array($runs) ? array_values($runs) : [],
            pagination: is_array($pagination) ? $pagination : [],
        );
    }
}
