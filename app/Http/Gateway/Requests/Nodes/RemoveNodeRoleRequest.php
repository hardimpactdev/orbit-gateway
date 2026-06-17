<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeRoleMutationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveNodeRoleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $node,
        public readonly string $role,
        public readonly bool $force,
        public readonly bool $purgeData,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/nodes/{$this->node}/roles/{$this->role}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'force' => $this->force,
            'purge_data' => $this->purgeData,
        ];
    }

    public function createDtoFromResponse(Response $response): NodeRoleMutationResponse
    {
        $data = $this->unwrapData($response);

        return new NodeRoleMutationResponse(
            node: is_string($data['node'] ?? null) ? $data['node'] : $this->node,
            removedRole: is_string($data['role'] ?? null) ? $data['role'] : $this->role,
            purgedData: is_bool($data['purged_data'] ?? null) ? $data['purged_data'] : $this->purgeData,
        );
    }
}
