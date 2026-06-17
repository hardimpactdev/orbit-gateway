<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Apps;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Apps\AppCreateResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateAppRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $name,
        public readonly string $node,
        public readonly ?string $repository,
        public readonly string $root,
        public readonly string $phpVersion,
        public readonly ?string $domain,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/apps';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            'node' => $this->node,
            'repository' => $this->repository,
            'root' => $this->root,
            'php_version' => $this->phpVersion,
            'domain' => $this->domain,
        ];
    }

    public function createDtoFromResponse(Response $response): AppCreateResponse
    {
        $data = $this->unwrapData($response);
        $body = $response->json();
        $warnings = [];

        if (is_array($body) && isset($body['success']['meta']['warnings']) && is_array($body['success']['meta']['warnings'])) {
            $warnings = array_values($body['success']['meta']['warnings']);
        }

        return new AppCreateResponse(
            data: $data,
            warnings: $warnings,
        );
    }
}
