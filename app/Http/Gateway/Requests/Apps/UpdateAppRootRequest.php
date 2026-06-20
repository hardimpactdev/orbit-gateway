<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Apps;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Apps\AppRootUpdateResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateAppRootRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $app,
        public readonly string $root,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/apps/'.rawurlencode($this->app).'/root';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'root' => $this->root,
        ];
    }

    public function createDtoFromResponse(Response $response): AppRootUpdateResponse
    {
        $data = $this->unwrapData($response);
        $body = $response->json();
        $warnings = [];
        $artifactsReenacted = false;

        if (is_array($body) && isset($body['success']['meta']) && is_array($body['success']['meta'])) {
            $meta = $body['success']['meta'];

            if (isset($meta['warnings']) && is_array($meta['warnings'])) {
                $warnings = array_values($meta['warnings']);
            }

            $artifactsReenacted = $meta['artifacts_reenacted'] ?? false;
            $artifactsReenacted = is_bool($artifactsReenacted) ? $artifactsReenacted : false;
        }

        return new AppRootUpdateResponse(
            data: $data,
            warnings: $warnings,
            artifactsReenacted: $artifactsReenacted,
        );
    }
}
