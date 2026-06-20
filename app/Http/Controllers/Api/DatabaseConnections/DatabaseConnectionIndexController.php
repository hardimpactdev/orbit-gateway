<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Enums\ActivityLogType;
use App\Models\DatabaseConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseConnectionIndexController extends DatabaseConnectionApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $app = $this->stringValue($request->query('app'));
        $workspace = $this->stringValue($request->query('workspace'));
        $node = $this->stringValue($request->query('node'));
        $this->setActivityProperties($request, array_filter(compact('app', 'workspace', 'node'), static fn (mixed $value): bool => $value !== null));

        if ($app !== null && $workspace !== null) {
            return $this->validationFailed('scope', 'Invalid scope: --app and --workspace cannot be combined.', ['field' => 'scope']);
        }

        $appModel = $app !== null ? $this->resolver->resolveApp($app) : null;
        $workspaceModel = $workspace !== null ? $this->resolver->resolveWorkspace($workspace) : null;
        $nodeModel = $node !== null ? $this->resolver->resolveNode($node) : null;

        if ($app !== null && $appModel === null) {
            return $this->validationFailed('app', "Invalid value for --app: '{$app}'.", ['field' => 'app', 'value' => $app]);
        }

        if ($workspace !== null && $workspaceModel === null) {
            return $this->validationFailed('workspace', "Invalid value for --workspace: '{$workspace}'.", ['field' => 'workspace', 'value' => $workspace]);
        }

        if ($node !== null && $nodeModel === null) {
            return $this->validationFailed('node', "Invalid value for --node: '{$node}'.", ['field' => 'node', 'value' => $node]);
        }

        $authorization = $this->authorizeListScope($auth, $appModel, $workspaceModel, $nodeModel);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $connections = $this->registry->list(app: $appModel, workspace: $workspaceModel, node: $nodeModel);

        if (! $this->roles->nodeIsGateway($auth) && $appModel === null && $workspaceModel === null && $nodeModel === null) {
            $connections = $connections
                ->filter(fn (DatabaseConnection $connection): bool => $this->connectionAllowsAny($auth, $connection, 'database:read'))
                ->values();
        }

        $connections = $connections
            ->map(fn (DatabaseConnection $connection): array => $this->payloads->toArray($connection))
            ->all();

        return response()->json([
            'success' => [
                'data' => ['connections' => $connections],
                'meta' => ['count' => count($connections)],
            ],
        ]);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /database-connections';
    }
}
