<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Schedules;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleLogsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowScheduleLogsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        public readonly string $name,
        public readonly ?string $app = null,
        public readonly ?string $node = null,
        public readonly ?int $run = null,
        public readonly ?int $lines = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/schedules/'.rawurlencode($this->name).'/logs';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return array_filter([
            'app' => $this->app,
            'node' => $this->node,
            'run' => $this->run,
            'lines' => $this->lines,
        ], fn (string|int|null $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): ScheduleLogsResponse
    {
        return new ScheduleLogsResponse(
            data: $this->unwrapData($response),
            meta: $this->unwrapMeta($response),
        );
    }
}
