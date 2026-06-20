<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Orbit\Core\Http\JsonEnvelope;

/**
 * Returns a minimal, stable gateway status payload.
 * No framework internals or secrets are exposed.
 */
final readonly class GatewayStatusController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(JsonEnvelope::success([
            'version' => config('app.version', '0.1.0'),
            'time' => now()->toIso8601ZuluString(),
        ]));
    }
}
