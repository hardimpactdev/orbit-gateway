<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Processes;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Processes\ProcessRemoveResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveProcessRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $app,
        public readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/processes/'.rawurlencode($this->name);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'app' => $this->app,
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ];
    }

    public function createDtoFromResponse(Response $response): ProcessRemoveResponse
    {
        $data = $this->unwrapData($response);
        $body = $response->json();
        $warnings = [];

        if (is_array($body) && isset($body['success']['meta']) && is_array($body['success']['meta'])) {
            $meta = $body['success']['meta'];

            if (isset($meta['warnings']) && is_array($meta['warnings'])) {
                $warnings = array_values($meta['warnings']);
            }
        }

        return new ProcessRemoveResponse(
            data: $data,
            warnings: $warnings,
        );
    }
}
