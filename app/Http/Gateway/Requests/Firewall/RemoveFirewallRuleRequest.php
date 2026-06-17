<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Firewall;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Firewall\FirewallRuleMutationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveFirewallRuleRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $name,
        public readonly string $node,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/firewall-rules/{$this->name}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [
            'node' => $this->node,
            'destructive_consent' => true,
        ];
    }

    public function createDtoFromResponse(Response $response): FirewallRuleMutationResponse
    {
        return new FirewallRuleMutationResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
