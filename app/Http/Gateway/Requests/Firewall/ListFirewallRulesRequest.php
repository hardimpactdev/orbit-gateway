<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Firewall;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Firewall\FirewallRuleListResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListFirewallRulesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/firewall-rules';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'node' => $this->node,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): FirewallRuleListResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $rules = $data['rules'] ?? [];

        return new FirewallRuleListResponse(
            rules: is_array($rules) ? array_values($rules) : [],
            meta: $meta,
        );
    }
}
