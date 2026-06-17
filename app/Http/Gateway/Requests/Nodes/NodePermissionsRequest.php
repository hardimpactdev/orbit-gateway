<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodePermissionsResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class NodePermissionsRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $consumingNode,
        public readonly string $servingNode,
        public readonly ?string $preset = null,
        public readonly ?string $permissions = null,
        public readonly ?string $add = null,
        public readonly ?string $remove = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/nodes/permissions';
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

        if ($this->add !== null) {
            $body['add'] = $this->add;
        }

        if ($this->remove !== null) {
            $body['remove'] = $this->remove;
        }

        return $body;
    }

    public function createDtoFromResponse(Response $response): NodePermissionsResponse
    {
        $json = $response->json();
        $data = [];
        $warnings = [];

        if (is_array($json)) {
            if (isset($json['success']['data']) && is_array($json['success']['data'])) {
                $data = $json['success']['data'];
            }

            if (isset($json['success']['meta']['warnings']) && is_array($json['success']['meta']['warnings'])) {
                foreach ($json['success']['meta']['warnings'] as $warning) {
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
        }

        $permissions = null;
        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $permissions = array_values(array_filter($data['permissions'], is_string(...)));
        }

        return new NodePermissionsResponse(
            consumingNode: is_string($data['consuming_node'] ?? null) ? $data['consuming_node'] : $this->consumingNode,
            servingNode: is_string($data['serving_node'] ?? null) ? $data['serving_node'] : $this->servingNode,
            action: is_string($data['action'] ?? null) ? $data['action'] : 'read',
            permissions: $permissions,
            mode: is_string($data['mode'] ?? null) ? $data['mode'] : null,
            warnings: $warnings,
        );
    }
}
