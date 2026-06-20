<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Ca\OrbitCaService;
use Illuminate\Http\JsonResponse;

final readonly class CaRootController
{
    public function __construct(
        private OrbitCaService $caService,
    ) {}

    public function __invoke(): JsonResponse
    {
        try {
            $rootCa = $this->caService->rootCert();
        } catch (\RuntimeException) {
            return response()->json([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway root CA is not available.',
                    'meta' => [
                        'reason' => 'ca_not_bootstrapped',
                    ],
                ],
            ], 503);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'root_ca' => $rootCa,
                ],
            ],
        ]);
    }
}
