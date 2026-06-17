<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Workspaces;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Workspaces\CreateWorkspaceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateWorkspaceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $name,
        public readonly string $app,
        public readonly ?string $base = null,
        public readonly ?string $phpVersion = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/workspaces';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'name' => $this->name,
            'app' => $this->app,
            'base' => $this->base,
            'php_version' => $this->phpVersion,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): CreateWorkspaceResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $workspace = is_array($data['workspace'] ?? null) ? $data['workspace'] : [];
        $agentIde = is_array($workspace['agent_ide'] ?? null)
            ? $workspace['agent_ide']
            : ['adapter' => null, 'workspace_id' => null];

        return new CreateWorkspaceResponse(
            name: is_string($workspace['name'] ?? null) ? $workspace['name'] : $this->name,
            app: is_string($workspace['app'] ?? null) ? $workspace['app'] : $this->app,
            node: is_string($workspace['node'] ?? null) ? $workspace['node'] : null,
            path: is_string($workspace['path'] ?? null) ? $workspace['path'] : null,
            url: is_string($workspace['url'] ?? null) ? $workspace['url'] : null,
            phpVersion: is_string($workspace['php_version'] ?? null) ? $workspace['php_version'] : null,
            phpInherited: is_bool($workspace['php_inherited'] ?? null) ? $workspace['php_inherited'] : false,
            agentIde: $agentIde,
            adopted: is_bool($workspace['adopted'] ?? null) ? $workspace['adopted'] : false,
            lifecycleStatus: is_string($workspace['lifecycle_status'] ?? null) ? $workspace['lifecycle_status'] : 'setup-pending',
            base: is_string($meta['base'] ?? null) ? $meta['base'] : ($this->base ?? 'main'),
            action: is_string($data['result']['action'] ?? null) ? $data['result']['action'] : 'created',
            httpProbe: is_array($meta['http_probe'] ?? null) ? $meta['http_probe'] : [],
            warnings: is_array($meta['warnings'] ?? null) ? array_values($meta['warnings']) : [],
        );
    }
}
