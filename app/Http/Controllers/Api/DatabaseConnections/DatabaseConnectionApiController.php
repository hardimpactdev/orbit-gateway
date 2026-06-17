<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DatabaseConnections;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseAuditPayload;
use App\Services\DatabaseConnections\DatabaseConnectionExecutor;
use App\Services\DatabaseConnections\DatabaseConnectionPayloadMapper;
use App\Services\DatabaseConnections\DatabaseConnectionRegistry;
use App\Services\DatabaseConnections\DatabaseConnectionRegistryFailure;
use App\Services\DatabaseConnections\DatabaseConnectionSelector;
use App\Services\DatabaseConnections\DatabaseConnectionTargetResolver;
use App\Services\DatabaseConnections\DatabaseQueryRunnerFailure;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class DatabaseConnectionApiController implements Loggable
{
    private const string ActivityPropertiesAttribute = 'database_connection_activity_properties';

    private const string ActivitySubjectAttribute = 'database_connection_activity_subject';

    public function __construct(
        protected readonly DatabaseConnectionRegistry $registry,
        protected readonly DatabaseConnectionPayloadMapper $payloads,
        protected readonly DatabaseConnectionTargetResolver $resolver,
        protected readonly NodeRoleAssignments $roles,
        protected readonly NodeAccessAuthorizer $authorizer,
        protected readonly DatabaseConnectionSelector $selector,
        protected readonly DatabaseConnectionExecutor $executor,
        protected readonly DatabaseAuditPayload $audit,
    ) {}

    abstract public function effect(): ActivityLogType;

    abstract public function type(): string;

    public function subject(): ?Model
    {
        $subject = request()->attributes->get(self::ActivitySubjectAttribute);

        return $subject instanceof DatabaseConnection ? $subject : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        $properties = request()->attributes->get(self::ActivityPropertiesAttribute);

        return is_array($properties) ? $properties : [];
    }

    public function description(): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function setActivityProperties(Request $request, array $properties): void
    {
        $request->attributes->set(self::ActivityPropertiesAttribute, $properties);
    }

    protected function setActivitySubject(Request $request, DatabaseConnection $connection): void
    {
        $request->attributes->set(self::ActivitySubjectAttribute, $connection);
    }

    protected function authorizeCaller(Request $request): JsonResponse|Node
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node || ! $caller->isActive()) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => (object) [],
                ],
            ], 403);
        }

        return $caller;
    }

    protected function authorizeListScope(Node $caller, ?App $app, ?Workspace $workspace, ?Node $node): ?JsonResponse
    {
        if ($app instanceof App) {
            return $this->authorizeNodePermission($caller, $this->ownerNode($app), 'database:read');
        }

        if ($workspace instanceof Workspace) {
            return $this->authorizeNodePermission($caller, $this->ownerNode($workspace), 'database:read');
        }

        if ($node instanceof Node) {
            return $this->authorizeNodePermission($caller, $node, 'database:read');
        }

        if ($this->roles->nodeIsGateway($caller)) {
            return null;
        }

        foreach (Node::query()->get() as $servingNode) {
            if ($this->authorizer->allows($caller, $servingNode, 'database:read')) {
                return null;
            }
        }

        return $this->authorizationFailed($caller, 'database:read');
    }

    protected function authorizeNodePermission(Node $caller, ?Node $servingNode, string $permission): ?JsonResponse
    {
        if ($this->roles->nodeIsGateway($caller)) {
            return null;
        }

        if ($servingNode instanceof Node) {
            $result = $this->authorizer->authorize($caller, $servingNode, $permission);

            if ($result->allowed) {
                return null;
            }

            return $this->authorizationFailed($caller, $result->missingPermission ?? $permission, $servingNode);
        }

        return $this->authorizationFailed($caller, $permission, $servingNode);
    }

    protected function authorizeConnectionPermission(Node $caller, DatabaseConnection $connection, string $permission, bool $requireAll = false): ?JsonResponse
    {
        if ($this->roles->nodeIsGateway($caller)) {
            return null;
        }

        $nodes = $this->connectionServingNodes($connection);

        if ($nodes === []) {
            return $this->authorizeNodePermission($caller, $this->gatewayNode(), $permission);
        }

        if ($requireAll) {
            foreach ($nodes as $node) {
                $authorization = $this->authorizeNodePermission($caller, $node, $permission);

                if ($authorization instanceof JsonResponse) {
                    return $authorization;
                }
            }

            return null;
        }

        foreach ($nodes as $node) {
            if ($this->authorizer->allows($caller, $node, $permission)) {
                return null;
            }
        }

        return $this->authorizationFailed($caller, $permission, servingNodes: array_map(
            static fn (Node $node): string => $node->name,
            $nodes,
        ));
    }

    protected function connectionAllowsAny(Node $caller, DatabaseConnection $connection, string $permission): bool
    {
        if ($this->roles->nodeIsGateway($caller)) {
            return true;
        }

        $nodes = $this->connectionServingNodes($connection);

        if ($nodes === []) {
            $gateway = $this->gatewayNode();

            return $gateway instanceof Node && $this->authorizer->allows($caller, $gateway, $permission);
        }

        return array_any($nodes, fn ($node) => $this->authorizer->allows($caller, $node, $permission));
    }

    /**
     * @return list<Node>
     */
    protected function connectionServingNodes(DatabaseConnection $connection): array
    {
        $connection->loadMissing(['node', 'targets.app.node', 'targets.workspace.app.node', 'instanceTargets.instance.app.node']);

        $nodes = [];

        if ($connection->node instanceof Node) {
            $nodes[$connection->node->id] = $connection->node;
        }

        foreach ($connection->targets as $target) {
            if ($target->app?->node instanceof Node) {
                $nodes[$target->app->node->id] = $target->app->node;
            }

            if ($target->workspace?->app?->node instanceof Node) {
                $nodes[$target->workspace->app->node->id] = $target->workspace->app->node;
            }
        }

        foreach ($connection->instanceTargets as $target) {
            if ($target->instance->app->node instanceof Node) {
                $nodes[$target->instance->app->node->id] = $target->instance->app->node;
            }
        }

        return array_values($nodes);
    }

    protected function targetOwnerNode(string $target): ?Node
    {
        $app = $this->resolver->resolveApp($target);

        if ($app instanceof App) {
            return $this->ownerNode($app);
        }

        $workspace = $this->resolver->resolveWorkspace($target);

        if ($workspace instanceof Workspace) {
            return $this->ownerNode($workspace);
        }

        return null;
    }

    protected function ownerNode(App|Workspace|AppInstance $owner): ?Node
    {
        if ($owner instanceof AppInstance) {
            $owner->loadMissing('app.node');

            return $owner->app->node;
        }

        if ($owner instanceof App) {
            $owner->loadMissing('node');

            return $owner->node;
        }

        $owner->loadMissing('app.node');

        return $owner->app?->node;
    }

    protected function gatewayNode(): ?Node
    {
        $gateway = $this->roles->activeGatewayNodeQuery()->first();

        return $gateway instanceof Node ? $gateway : null;
    }

    /**
     * @param  list<string>  $servingNodes
     */
    protected function authorizationFailed(Node $caller, string $permission, ?Node $servingNode = null, array $servingNodes = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => 'This node is not authorized to manage database connections.',
                'meta' => array_filter([
                    'reason' => 'missing_permission',
                    'missing_permission' => $permission,
                    'serving_node' => $servingNode?->name,
                    'serving_nodes' => $servingNodes === [] ? null : $servingNodes,
                ], static fn (mixed $value): bool => $value !== null),
            ],
        ], 403);
    }

    /**
     * @return array<string, mixed>
     */
    protected function connectionPayload(Request $request, bool $allowPartial = false): array
    {
        $payload = [];
        $nodeSelector = $this->stringValue($request->input('node'));

        foreach (['slug', 'driver', 'host', 'database', 'path', 'username', 'password'] as $field) {
            $value = $this->stringValue($request->input($field));

            if ($value !== null || (! $allowPartial && $request->has($field))) {
                $payload[$field] = $value;
            }
        }

        if ($request->has('port')) {
            $payload['port'] = $request->input('port');
        }

        if ($request->boolean('clear_password')) {
            $payload['clear_password'] = true;
        }

        if ($request->has('node')) {
            $node = $this->resolver->resolveNode($nodeSelector);

            if ($nodeSelector !== null && $node === null) {
                $payload['__invalid_node'] = $nodeSelector;
            } else {
                $payload['node_id'] = $node?->id;
            }
        }

        $this->setActivityProperties($request, array_filter([
            'slug' => $payload['slug'] ?? $this->stringValue($request->route('connection')),
            'driver' => $payload['driver'] ?? null,
            'node' => $this->stringValue($request->input('node')),
        ], static fn (mixed $value): bool => $value !== null));

        return $payload;
    }

    /**
     * @return array{0: string, 1: App|Workspace|AppInstance}|JsonResponse
     */
    protected function resolveTargetScope(Request $request, string $envPrefix): array|JsonResponse
    {
        $app = $this->stringValue($request->input('app'));
        $instance = $this->stringValue($request->input('instance'));
        $workspace = $this->stringValue($request->input('workspace'));

        if ($instance !== null && $app === null) {
            return $this->validationFailed('app', 'The --app option is required when --instance is used.', ['field' => 'app'], 422);
        }

        if (($app === null && $workspace === null) || ($app !== null && $workspace !== null)) {
            return $this->validationFailed('scope', 'Exactly one of app or workspace is required.', ['field' => 'scope'], 422);
        }

        if (! $this->resolver->validEnvPrefix($envPrefix)) {
            return $this->validationFailed('env_prefix', 'Environment prefix must start with a letter and use only uppercase letters, digits, or underscores.', [
                'field' => 'env_prefix',
                'value' => $envPrefix,
            ], 422);
        }

        if ($app !== null) {
            $appModel = $this->resolver->resolveApp($app);

            if ($appModel === null) {
                return $this->validationFailed('app', "Invalid value for --app: '{$app}'.", ['field' => 'app', 'value' => $app], 422);
            }

            if ($instance !== null) {
                $instanceModel = $this->resolver->resolveAppInstance($appModel, $instance);

                if (! $instanceModel instanceof AppInstance) {
                    return $this->validationFailed('instance', "Invalid value for --instance: '{$instance}'.", [
                        'field' => 'instance',
                        'app' => $appModel->name,
                        'value' => $instance,
                    ], 422);
                }

                return ['app_instance', $instanceModel];
            }

            return ['app', $appModel];
        }

        $workspaceModel = $this->resolver->resolveWorkspace($workspace);

        if ($workspaceModel === null) {
            return $this->validationFailed('workspace', "Invalid value for --workspace: '{$workspace}'.", ['field' => 'workspace', 'value' => $workspace], 422);
        }

        return ['workspace', $workspaceModel];
    }

    protected function connectionResponse(Request $request, DatabaseConnection|DatabaseConnectionRegistryFailure $result, int $successStatus): JsonResponse
    {
        if ($result instanceof DatabaseConnectionRegistryFailure) {
            return $this->failureResponse($result);
        }

        $this->setActivitySubject($request, $result);

        return response()->json([
            'success' => [
                'data' => [
                    'connection' => $this->payloads->toArray($result),
                ],
                'meta' => (object) [],
            ],
        ], $successStatus);
    }

    protected function schemaOperation(Request $request, string $operation): JsonResponse
    {
        $auth = $this->authorizeCaller($request);

        if ($auth instanceof JsonResponse) {
            return $auth;
        }

        $target = $this->stringValue($request->query('target'));

        if ($target === null) {
            return $this->validationFailed('target', 'Target is required.', ['field' => 'target'], 422);
        }

        $connection = $this->selector->resolve($target, $this->stringValue($request->query('connection')));

        if ($connection instanceof DatabaseConnectionRegistryFailure) {
            $this->setActivityProperties($request, [
                'operation' => $operation,
                'target' => $target,
                'selected_connection' => $this->stringValue($request->query('connection')),
                'table' => $this->stringValue($request->query('table')),
                'exit_status' => 'failed',
            ]);

            return $this->failureResponse($connection);
        }

        $requiredPermission = 'database:read';
        $targetNode = $this->targetOwnerNode($target);
        $authorization = $targetNode instanceof Node
            ? $this->authorizeNodePermission($auth, $targetNode, $requiredPermission)
            : $this->authorizeConnectionPermission($auth, $connection, $requiredPermission);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $this->setActivitySubject($request, $connection);
        $table = $operation === 'describe' ? $this->stringValue($request->query('table')) ?? '' : null;
        $this->setActivityProperties($request, $this->audit->schema($operation, $connection, $target, table: $table));

        try {
            $result = match ($operation) {
                'tables' => $this->executor->tables($connection),
                'schema' => $this->executor->schema($connection),
                'describe' => $this->executor->describe($connection, $table ?? ''),
                default => $this->executor->schema($connection),
            };
            $this->setActivityProperties($request, $this->audit->schema($operation, $connection, $target, $result['meta'], $table, [
                'exit_status' => 'success',
            ]));

            return $this->operationResponse($result['data'], $result['meta'], $connection);
        } catch (DatabaseQueryRunnerFailure $failure) {
            $this->setActivityProperties($request, $this->audit->schema($operation, $connection, $target, $failure->meta, $table, [
                'exit_status' => 'failed',
                'error_code' => $failure->errorCode,
            ]));

            return $this->queryFailureResponse($failure);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function operationResponse(array $data, array $meta, DatabaseConnection $connection): JsonResponse
    {
        return response()->json([
            'success' => [
                'data' => $data,
                'meta' => [
                    'connection' => $connection->slug,
                    'driver' => $connection->driver,
                    ...$meta,
                ],
            ],
        ]);
    }

    protected function queryFailureResponse(DatabaseQueryRunnerFailure $failure): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $failure->errorCode,
                'message' => $failure->getMessage(),
                'meta' => $failure->meta === [] ? (object) [] : $failure->meta,
            ],
        ], $failure->errorCode === 'database_query.write_not_allowed' ? 422 : 400);
    }

    protected function failureResponse(DatabaseConnectionRegistryFailure $failure): JsonResponse
    {
        $status = match ($failure->code) {
            'database_connection.not_found', 'database_connection.target_not_found' => 404,
            'authorization_failed' => 403,
            'validation_failed', 'database_connection.target_conflict', 'database_connection.slug_taken' => 422,
            default => 400,
        };

        return response()->json([
            'error' => [
                'code' => $failure->code,
                'message' => $failure->message,
                'meta' => $failure->meta === [] ? (object) [] : $failure->meta,
            ],
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function validationFailed(string $field, string $message, array $meta, int $status = 400): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], $status);
    }

    protected function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
