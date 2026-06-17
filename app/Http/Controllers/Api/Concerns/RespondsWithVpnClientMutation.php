<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Data\Vpn\VpnClientMutationResult;
use App\Services\Vpn\VpnFailure;
use Illuminate\Http\JsonResponse;

trait RespondsWithVpnClientMutation
{
    abstract protected function fail(VpnFailure $failure, int $status = 400): JsonResponse;

    protected function respondWithVpnClientMutation(VpnClientMutationResult|VpnFailure $result): JsonResponse
    {
        if ($result instanceof VpnFailure) {
            return $this->fail($result, 422);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'client' => [
                        'name' => $result->client->name,
                        'enabled' => $result->client->enabled,
                        'action' => $result->action,
                        'already_'.$result->action => $result->alreadyInDesiredState,
                    ],
                ],
                'meta' => (object) [],
            ],
        ]);
    }
}
