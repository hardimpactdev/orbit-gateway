<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Profile;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Profile\ProfileResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowProfileRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $target,
        public readonly string $uri = '/',
        public readonly string $authMode = 'guest',
        public readonly ?string $user = null,
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/profile';
    }

    /**
     * @return array<string, string>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'target' => $this->target,
            'uri' => $this->uri,
            'auth_mode' => $this->authMode,
            'user' => $this->user,
            'node' => $this->node,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): ProfileResponse
    {
        return new ProfileResponse(
            data: $this->unwrapData($response),
        );
    }
}
