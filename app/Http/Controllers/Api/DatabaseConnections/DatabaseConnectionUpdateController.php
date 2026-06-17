<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseConnectionUpdateController extends DatabaseConnectionApiController
{
    public function __invoke(Request $request, string $connection): JsonResponse
    {
        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $payload = $this->connectionPayload($request, allowPartial: true);

        if ($payload === []) {
            return $this->validationFailed('payload', 'At least one mutable field is required.', ['field' => 'payload']);
        }

        if (isset($payload['__invalid_node'])) {
            return $this->validationFailed('node', "Invalid value for --node: '{$payload['__invalid_node']}'.", [
                'field' => 'node',
                'value' => $payload['__invalid_node'],
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

        if (array_key_exists('node_id', $payload)) {
            $newServingNode = $payload['node_id'] !== null ? Node::query()->find($payload['node_id']) : $this->gatewayNode();
            $authorization = $this->authorizeNodePermission($auth, $newServingNode, 'database:write');

            if ($authorization instanceof JsonResponse) {
                return $authorization;
            }
        }

        $result = $this->registry->update($connection, $payload);

        return $this->connectionResponse($request, $result, 200);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:PATCH /database-connections/{connection}';
    }
}
