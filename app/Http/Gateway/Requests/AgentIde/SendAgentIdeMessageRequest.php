<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\AgentIde;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\AgentIde\AgentIdeMessageResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class SendAgentIdeMessageRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $message,
        public readonly ?string $app = null,
        public readonly ?string $workspace = null,
        public readonly ?string $path = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/agent-ide/message';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'message' => $this->message,
            'app' => $this->app,
            'workspace' => $this->workspace,
            'path' => $this->path,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): AgentIdeMessageResponse
    {
        return new AgentIdeMessageResponse(
            data: $this->unwrapData($response),
        );
    }
}
