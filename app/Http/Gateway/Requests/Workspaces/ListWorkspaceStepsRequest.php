<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Workspaces;

use App\Enums\WorkspaceLifecyclePhase;
use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceStepListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListWorkspaceStepsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly WorkspaceLifecyclePhase $phase,
        public readonly ?string $app = null,
        public readonly ?string $path = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/workspaces/steps/{$this->phase->value}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'path' => $this->path,
        ], fn (mixed $value): bool => is_scalar($value) && (string) $value !== '');
    }

    public function createDtoFromResponse(Response $response): WorkspaceStepListResponse
    {
        $data = $this->unwrapData($response);
        $steps = $data['steps'] ?? [];

        return new WorkspaceStepListResponse(
            steps: is_array($steps) ? array_values($steps) : [],
        );
    }
}
