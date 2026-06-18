<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Schedules;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleAddResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AddScheduleRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $name,
        public readonly ?string $app,
        public readonly ?string $node,
        public readonly string $interval,
        public readonly string $timezone,
        public readonly ?string $command,
        public readonly ?string $script,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/schedules';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return array_filter([
            'name' => $this->name,
            'app' => $this->app,
            'node' => $this->node,
            'interval' => $this->interval,
            'timezone' => $this->timezone,
            'command' => $this->command,
            'script' => $this->script,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function createDtoFromResponse(Response $response): ScheduleAddResponse
    {
        return new ScheduleAddResponse(
            data: $this->unwrapData($response),
        );
    }
}
