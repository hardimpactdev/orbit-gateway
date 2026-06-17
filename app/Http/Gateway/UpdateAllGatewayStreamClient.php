<?php

declare(strict_types=1);

namespace App\Http\Gateway;

use App\Contracts\UpdateAllGatewayStream;
use App\Http\Gateway\Requests\Operations\UpdateAllStreamRequest;

final readonly class UpdateAllGatewayStreamClient implements UpdateAllGatewayStream
{
    public function __construct(
        private ?GatewayStreamTransport $streams = null,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function run(callable $onEvent): int|GatewayApiException
    {
        return ($this->streams ?? app(GatewayStreamTransport::class))->events(
            request: new UpdateAllStreamRequest,
            onEvent: $onEvent,
            unavailableMessage: 'Gateway connection is required to update the fleet.',
            defaultExitCode: 0,
        );
    }
}
