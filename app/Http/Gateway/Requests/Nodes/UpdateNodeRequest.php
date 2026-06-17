<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Nodes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Nodes\NodeUpdateResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    /**
     * @param  array<string, string|null>  $fields
     */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/nodes/{$this->name}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter(
            $this->fields,
            static fn (?string $value): bool => $value !== null,
        );
    }

    public function createDtoFromResponse(Response $response): NodeUpdateResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);

        $changed = $data['changed'] ?? [];
        $warnings = $meta['warnings'] ?? [];

        return new NodeUpdateResponse(
            name: is_string($data['name'] ?? null) ? $data['name'] : $this->name,
            changed: is_array($changed) ? array_values(array_filter($changed, is_string(...))) : [],
            warnings: is_array($warnings) ? $this->normalizeWarnings($warnings) : [],
        );
    }

    /**
     * @param  array<int, mixed>  $warnings
     * @return list<array<string, string>>
     */
    private function normalizeWarnings(array $warnings): array
    {
        return array_values(array_filter(array_map(
            static function (mixed $warning): ?array {
                if (! is_array($warning)) {
                    return null;
                }

                return array_filter([
                    'code' => is_string($warning['code'] ?? null) ? $warning['code'] : null,
                    'message' => is_string($warning['message'] ?? null) ? $warning['message'] : null,
                    'family' => is_string($warning['family'] ?? null) ? $warning['family'] : null,
                    'next_command' => is_string($warning['next_command'] ?? null) ? $warning['next_command'] : null,
                ], is_string(...));
            },
            $warnings,
        )));
    }
}
