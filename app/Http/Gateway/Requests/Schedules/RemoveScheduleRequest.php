<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Schedules;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleRemoveResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveScheduleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        public readonly string $name,
        public readonly ?string $app = null,
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/schedules/'.rawurlencode($this->name);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'node' => $this->node,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'destructive_consent' => true,
            'destructive_consent_source' => 'force',
        ];
    }

    public function createDtoFromResponse(Response $response): ScheduleRemoveResponse
    {
        return new ScheduleRemoveResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
