<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeRevokeResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RevokeNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $consumingNode,
        public readonly string $servingNode,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/nodes/revoke';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'consuming_node' => $this->consumingNode,
            'serving_node' => $this->servingNode,
            'force' => true,
        ];
    }

    public function createDtoFromResponse(Response $response): NodeRevokeResponse
    {
        $data = $this->unwrapData($response);

        return new NodeRevokeResponse(
            consumingNode: is_string($data['consuming_node'] ?? null) ? $data['consuming_node'] : $this->consumingNode,
            servingNode: is_string($data['serving_node'] ?? null) ? $data['serving_node'] : $this->servingNode,
            alreadyAbsent: is_bool($data['already_absent'] ?? null) ? $data['already_absent'] : false,
            selfLockout: is_bool($data['self_lockout'] ?? null) ? $data['self_lockout'] : false,
            wasGatewayAdmin: is_bool($data['was_gateway_admin'] ?? null) ? $data['was_gateway_admin'] : false,
        );
    }
}
