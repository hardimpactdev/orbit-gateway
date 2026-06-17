<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Workspaces;

use App\Enums\WorkspaceLifecyclePhase;
use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceStepMutationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveWorkspaceStepRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly WorkspaceLifecyclePhase $phase,
        public readonly int $step,
        public readonly ?string $app = null,
        public readonly ?string $path = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/workspaces/steps/{$this->phase->value}/{$this->step}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'path' => $this->path,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ];
    }

    public function createDtoFromResponse(Response $response): WorkspaceStepMutationResponse
    {
        $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        $success = is_array($body) ? ($body['success'] ?? []) : [];
        $data = is_array($success) ? ($success['data'] ?? []) : [];
        $meta = is_array($success) ? ($success['meta'] ?? []) : [];
        $result = is_array($data) ? ($data['result'] ?? []) : [];
        $step = is_array($data) ? ($data['step'] ?? []) : [];

        return new WorkspaceStepMutationResponse(
            result: is_array($result) ? $result : [],
            step: is_array($step) ? $step : [],
            meta: is_array($meta) ? $meta : [],
        );
    }
}
