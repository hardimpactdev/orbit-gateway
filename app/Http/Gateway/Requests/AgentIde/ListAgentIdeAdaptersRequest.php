<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\AgentIde;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\AgentIde\AgentIdeAdapterChoicesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListAgentIdeAdaptersRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $scope,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/agent-ide/adapters';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [
            'scope' => $this->scope,
        ];
    }

    public function createDtoFromResponse(Response $response): AgentIdeAdapterChoicesResponse
    {
        $data = $this->unwrapData($response);

        return new AgentIdeAdapterChoicesResponse(
            scope: is_string($data['scope'] ?? null) ? $data['scope'] : $this->scope,
            reservedTokens: $this->listOfStrings($data['reserved_tokens'] ?? []),
            adapters: $this->listOfArrays($data['adapters'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private function listOfStrings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_array(...)));
    }
}
