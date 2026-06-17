<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Enums\ActivityLogType;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseConnectionDestroyController extends DatabaseConnectionApiController
{
    public function __invoke(Request $request, string $connection): JsonResponse
    {
        $this->setActivityProperties($request, ['slug' => $connection]);

        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        if (! $request->boolean('force')) {
            return $this->validationFailed('force', 'Use --force to remove this database connection.', [
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ], 422);
        }

        $existing = $this->registry->show($connection);

        if ($existing instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($existing);
        }

        $authorization = $this->authorizeConnectionPermission($auth, $existing, 'database:write', requireAll: true);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $result = $this->registry->remove($connection, true);

        if ($result instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($result);
        }

        return response()->json([
            'success' => [
                'data' => [
                    'result' => [
                        'action' => 'removed',
                        'connection' => $connection,
                    ],
                ],
                'meta' => (object) [],
            ],
        ]);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Destructive;
    }

    public function type(): string
    {
        return 'api:DELETE /database-connections/{connection}';
    }
}
