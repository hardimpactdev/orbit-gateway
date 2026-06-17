<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Schedules;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleShowResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowScheduleRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

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

    public function createDtoFromResponse(Response $response): ScheduleShowResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $schedule = $data['schedule'] ?? [];

        return new ScheduleShowResponse(
            schedule: is_array($schedule) ? $schedule : [],
            meta: $meta,
        );
    }
}
