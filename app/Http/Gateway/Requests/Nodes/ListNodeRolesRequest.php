<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeRoleListResponse;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListNodeRolesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $node,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/nodes/{$this->node}/roles";
    }

    public function createDtoFromResponse(Response $response): NodeRoleListResponse
    {
        $data = $this->unwrapData($response);
        $roles = is_array($data['roles'] ?? null) ? $data['roles'] : [];

        return new NodeRoleListResponse(
            node: is_string($data['node'] ?? null) ? $data['node'] : $this->node,
            roles: array_values(array_map(
                static fn (mixed $assignment): array => is_array($assignment) ? NodeRoleAssignmentPayload::fromArray($assignment) : [],
                $roles,
            )),
        );
    }
}
