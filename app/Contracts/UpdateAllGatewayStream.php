<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Http\Gateway\GatewayApiException;

interface UpdateAllGatewayStream
{
    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function run(callable $onEvent): int|GatewayApiException;
}
