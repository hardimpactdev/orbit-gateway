<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Schedules;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleManualRunResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RunScheduleRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $name,
        public readonly ?string $app = null,
        public readonly ?string $node = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/schedules/'.rawurlencode($this->name).'/run';
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

    public function createDtoFromResponse(Response $response): ScheduleManualRunResponse
    {
        return new ScheduleManualRunResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
