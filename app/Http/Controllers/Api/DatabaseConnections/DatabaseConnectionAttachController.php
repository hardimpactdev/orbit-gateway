<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Enums\ActivityLogType;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseConnectionAttachController extends DatabaseConnectionApiController
{
    public function __invoke(Request $request, string $connection): JsonResponse
    {
        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $envPrefix = $this->stringValue($request->input('env_prefix')) ?? 'DB';
        $scope = $this->resolveTargetScope($request, $envPrefix);

        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        [$type, $owner] = $scope;
        $existing = $this->registry->show($connection);

        if ($existing instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($existing);
        }

        $authorization = $this->authorizeNodePermission($auth, $this->ownerNode($owner), 'database:write');

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $this->setActivityProperties($request, [
            'slug' => $connection,
            'target_type' => $type,
            'target_name' => $owner->name,
            'env_prefix' => $envPrefix,
        ]);

        $result = $type === 'app'
            ? $this->registry->attachToApp($connection, $owner, $envPrefix)
            : $this->registry->attachToWorkspace($connection, $owner, $envPrefix);

        if ($result instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($result);
        }

        $connectionResult = $this->registry->show($connection);

        return $this->connectionResponse($request, $connectionResult, 200);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /database-connections/{connection}/targets';
    }
}
