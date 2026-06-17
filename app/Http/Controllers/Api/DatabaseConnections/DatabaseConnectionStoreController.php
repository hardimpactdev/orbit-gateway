<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Enums\ActivityLogType;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseConnectionStoreController extends DatabaseConnectionApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $payload = $this->connectionPayload($request);
        $slug = $this->stringValue($request->input('slug'));

        if ($slug === null) {
            return $this->validationFailed('slug', 'Database connection slug is required.', ['field' => 'slug']);
        }

        if (isset($payload['__invalid_node'])) {
            return $this->validationFailed('node', "Invalid value for --node: '{$payload['__invalid_node']}'.", [
                'field' => 'node',
                'value' => $payload['__invalid_node'],
            ], 422);
        }

        $servingNode = array_key_exists('node_id', $payload) && $payload['node_id'] !== null
            ? Node::query()->find($payload['node_id'])
            : $this->gatewayNode();
        $authorization = $this->authorizeNodePermission($auth, $servingNode, 'database:write');

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $result = $this->registry->create($slug, $payload);

        return $this->connectionResponse($request, $result, 200);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /database-connections';
    }
}
