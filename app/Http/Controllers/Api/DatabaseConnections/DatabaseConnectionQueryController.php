<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use App\Services\DatabaseConnections\DatabaseQueryRunnerFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseConnectionQueryController extends DatabaseConnectionApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $target = $this->stringValue($request->input('target'));
        $sql = $this->stringValue($request->input('sql'));

        if ($target === null) {
            return $this->validationFailed('target', 'Target is required.', ['field' => 'target'], 422);
        }

        if ($sql === null) {
            return $this->validationFailed('sql', 'SQL is required.', ['field' => 'sql'], 422);
        }

        $connection = $this->selector->resolve($target, $this->stringValue($request->input('connection')));

        if ($connection instanceof DatabaseConnectionRegistryFailure) {
            $this->setActivityProperties($request, $this->audit->queryAttempt($target, $sql, [
                'write' => $request->boolean('write'),
            ], extra: [
                'selected_connection' => $this->stringValue($request->input('connection')),
                'exit_status' => 'failed',
            ]));

            return $this->failureResponse($connection);
        }

        $requiredPermission = $request->boolean('write') ? 'database:query:write' : 'database:query';
        $targetNode = $this->targetOwnerNode($target);
        $authorization = $targetNode instanceof Node
            ? $this->authorizeNodePermission($auth, $targetNode, $requiredPermission)
            : $this->authorizeConnectionPermission($auth, $connection, $requiredPermission);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $this->setActivitySubject($request, $connection);
        $options = [
            'write' => $request->boolean('write'),
            'full' => $request->boolean('full'),
            'limit' => $request->input('limit'),
            'timeout' => $request->input('timeout'),
            'max_json_bytes' => $request->input('max_json_bytes'),
        ];
        $this->setActivityProperties($request, $this->audit->query($connection, $target, $sql, $options));

        try {
            $result = $this->executor->query($connection, $sql, $options);
            $this->setActivityProperties($request, $this->audit->query($connection, $target, $sql, $options, $result['meta'], [
                'affected_rows' => $result['data']['affected_rows'] ?? null,
                'exit_status' => 'success',
            ]));

            return $this->operationResponse($result['data'], $result['meta'], $connection);
        } catch (DatabaseQueryRunnerFailure $failure) {
            $this->setActivityProperties($request, $this->audit->query($connection, $target, $sql, $options, $failure->meta, [
                'exit_status' => 'failed',
                'error_code' => $failure->errorCode,
            ]));

            return $this->queryFailureResponse($failure);
        }
    }

    public function effect(): ActivityLogType
    {
        return request()->boolean('write') ? ActivityLogType::Write : ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:POST /database-connections/query';
    }
}
