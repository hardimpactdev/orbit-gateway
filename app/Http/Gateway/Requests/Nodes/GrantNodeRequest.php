<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeGrantResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class GrantNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $consumingNode,
        public readonly string $servingNode,
        public readonly ?string $preset = null,
        public readonly ?string $permissions = null,
        public readonly bool $force = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/nodes/grant';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        $body = [
            'consuming_node' => $this->consumingNode,
            'serving_node' => $this->servingNode,
        ];

        if ($this->preset !== null) {
            $body['preset'] = $this->preset;
        }

        if ($this->permissions !== null) {
            $body['permissions'] = $this->permissions;
        }

        if ($this->force) {
            $body['force'] = true;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): NodeGrantResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);

        $permissions = null;
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $permissions = array_values(array_filter($data['permissions'], is_string(...)));
        }

        $warnings = [];
        if (isset($meta['warnings']) && is_array($meta['warnings'])) {
            foreach ($meta['warnings'] as $warning) {
                if (! is_array($warning) || ! isset($warning['code'], $warning['message'])) {
                    continue;
                }

                $warnings[] = [
                    'code' => (string) $warning['code'],
                    'family' => is_string($warning['family'] ?? null) ? $warning['family'] : '',
                    'message' => (string) $warning['message'],
                    'next_command' => is_string($warning['next_command'] ?? null) ? $warning['next_command'] : null,
                    'permissions' => is_array($warning['permissions'] ?? null)
                        ? array_values(array_filter($warning['permissions'], is_string(...)))
                        : [],
                ];
            }
        }

        return new NodeGrantResponse(
            consumingNode: is_string($data['consuming_node'] ?? null) ? $data['consuming_node'] : $this->consumingNode,
            servingNode: is_string($data['serving_node'] ?? null) ? $data['serving_node'] : $this->servingNode,
            alreadyGranted: is_bool($data['already_granted'] ?? null) ? $data['already_granted'] : false,
            action: is_string($data['action'] ?? null) ? $data['action'] : 'granted',
            permissions: $permissions,
            warnings: $warnings,
        );
    }
}
