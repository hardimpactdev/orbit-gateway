<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\AgentIde\AgentIdeAdapterRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class AgentIdeAdapterChoicesController
{
    public function __construct(
        private AgentIdeAdapterRegistry $registry,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $scope = $request->query('scope');
        $scope = is_string($scope) ? $scope : '';

        if (! $this->registry->isSupportedScope($scope)) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Agent IDE adapter scope is not supported.',
                    'meta' => [
                        'scope' => $scope,
                        'supported' => $this->registry->supportedScopes(),
                    ],
                ],
            ], 422);
        }

        $choices = $this->registry->choicesForScope($scope);

        return response()->json([
            'success' => [
                'data' => [
                    'scope' => $scope,
                    'reserved_tokens' => $choices['reserved_tokens'],
                    'adapters' => $choices['adapters'],
                ],
            ],
        ]);
    }
}
