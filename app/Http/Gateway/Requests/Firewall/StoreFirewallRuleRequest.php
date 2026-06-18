<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Firewall;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Firewall\FirewallRuleMutationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class StoreFirewallRuleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $action,
        public readonly string $name,
        public readonly string $node,
        public readonly string $direction,
        public readonly string $source,
        public readonly ?string $destination,
        public readonly string $port,
        public readonly string $protocol,
        public readonly ?string $reason,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/firewall-rules';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'action' => $this->action,
            'name' => $this->name,
            'node' => $this->node,
            'direction' => $this->direction,
            'source' => $this->source,
            'destination' => $this->destination,
            'port' => $this->port,
            'protocol' => $this->protocol,
            'reason' => $this->reason,
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
