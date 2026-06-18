<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeAgentIdeResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class SetNodeAgentIdeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $name,
        public readonly string $agentIde,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/nodes/{$this->name}/agent-ide";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'agent_ide' => $this->agentIde,
        ];
    }

    public function createDtoFromResponse(Response $response): NodeAgentIdeResponse
    {
        $data = $this->unwrapData($response);
        $agentIde = $data['agent_ide'] ?? [];

        return new NodeAgentIdeResponse(
            name: is_string($data['name'] ?? null) ? $data['name'] : $this->name,
            agentIde: [
                'adapter' => is_string($agentIde['adapter'] ?? null) ? $agentIde['adapter'] : null,
                'source' => is_string($agentIde['source'] ?? null) ? $agentIde['source'] : 'default',
            ],
            action: is_string($data['action'] ?? null) ? $data['action'] : 'set',
        );
    }
}
