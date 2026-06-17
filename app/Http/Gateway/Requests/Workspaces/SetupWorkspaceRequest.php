<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Workspaces;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Workspaces\SetupWorkspaceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class SetupWorkspaceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $app = null,
        public readonly ?string $path = null,
        public readonly ?string $callerCwd = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/workspaces/setup';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'name' => $this->name,
            'app' => $this->app,
            'path' => $this->path,
            'caller_cwd' => $this->callerCwd,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): SetupWorkspaceResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);

        return new SetupWorkspaceResponse(
            app: is_string($data['app'] ?? null) ? $data['app'] : '',
            workspace: is_string($data['workspace'] ?? null) ? $data['workspace'] : '',
            node: is_string($data['node'] ?? null) ? $data['node'] : '',
            url: is_string($data['url'] ?? null) ? $data['url'] : '',
            action: is_string($data['action'] ?? null) ? $data['action'] : 'set_up',
            warnings: is_array($meta['warnings'] ?? null) ? array_values($meta['warnings']) : [],
            setupSteps: is_array($data['setup_steps'] ?? null) ? $data['setup_steps'] : [],
            processes: is_array($data['processes'] ?? null) ? $data['processes'] : [],
            httpProbe: is_array($data['http_probe'] ?? null) ? $data['http_probe'] : [],
        );
    }
}
