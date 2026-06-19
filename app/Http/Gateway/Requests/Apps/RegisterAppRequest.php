<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Apps;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Apps\AppRegisterResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RegisterAppRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $name,
        public readonly ?string $node,
        public readonly ?string $path,
        public readonly string $root,
        public readonly string $phpVersion,
        public readonly ?string $domain,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/apps/register';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            'node' => $this->node,
            'path' => $this->path,
            'root' => $this->root,
            'php_version' => $this->phpVersion,
            'domain' => $this->domain,
        ];
    }

    public function createDtoFromResponse(Response $response): AppRegisterResponse
    {
        $data = $this->unwrapData($response);
        $body = $response->json();
        $warnings = [];

        if (is_array($body) && isset($body['success']['meta']['warnings']) && is_array($body['success']['meta']['warnings'])) {
            $warnings = array_values($body['success']['meta']['warnings']);
        }

        return new AppRegisterResponse(
            data: $data,
            warnings: $warnings,
        );
    }
}
