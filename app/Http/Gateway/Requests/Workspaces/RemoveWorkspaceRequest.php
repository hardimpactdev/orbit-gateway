<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Workspaces;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Workspaces\WorkspaceRemoveResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveWorkspaceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $name,
        public readonly ?string $app = null,
        public readonly bool $keepFiles = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/workspaces/'.rawurlencode($this->name);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'keep_files' => $this->keepFiles,
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ];
    }

    public function createDtoFromResponse(Response $response): WorkspaceRemoveResponse
    {
        return new WorkspaceRemoveResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
