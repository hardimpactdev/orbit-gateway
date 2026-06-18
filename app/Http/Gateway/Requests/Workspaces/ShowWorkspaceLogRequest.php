<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Workspaces;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceLogResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowWorkspaceLogRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly int $run,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/workspaces/runs/{$this->run}/log";
    }

    public function createDtoFromResponse(Response $response): WorkspaceLogResponse
    {
        $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        $success = is_array($body) ? ($body['success'] ?? []) : [];
        $data = is_array($success) ? ($success['data'] ?? []) : [];
        $meta = is_array($success) ? ($success['meta'] ?? []) : [];
        $run = is_array($data) ? ($data['run'] ?? []) : [];

        return new WorkspaceLogResponse(
            run: is_array($run) ? $run : [],
            meta: is_array($meta) ? $meta : [],
        );
    }
}
