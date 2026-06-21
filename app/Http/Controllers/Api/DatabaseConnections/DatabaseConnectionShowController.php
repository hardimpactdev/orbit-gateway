<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Enums\ActivityLogType;
use App\Models\DatabaseConnection;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseConnectionShowController extends DatabaseConnectionApiController
{
    public function __invoke(Request $request, string $connection): JsonResponse
    {
        $this->setActivityProperties($request, ['slug' => $connection]);

        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $result = $this->registry->show($connection);

        if ($result instanceof DatabaseConnection) {
            $authorization = $this->authorizeConnectionPermission($auth, $result, 'database:read');

            if ($authorization instanceof JsonResponse) {
                return $authorization;
            }
        }

        if ($result instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($result);
        }

        return $this->connectionResponse($request, $result, 200);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /database-connections/{connection}';
    }
}
