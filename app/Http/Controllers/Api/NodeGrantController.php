<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeStatus;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\GrantNodeApiRequest;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

#[RequiresPermission('node:grant', servingNode: ServingNode::Gateway)]
final readonly class NodeGrantController implements Loggable
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function __invoke(GrantNodeApiRequest $request): JsonResponse
    {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $consumerName = $request->consumingNodeName();
        $servingName = $request->servingNodeName();

        $consumer = $this->resolveNode($consumerName, 'consuming_node');

        if ($consumer instanceof JsonResponse) {
            return $consumer;
        }

        $serving = $this->resolveNode($servingName, 'serving_node');

        if ($serving instanceof JsonResponse) {
            return $serving;
        }

        $permissions = $this->resolvePermissions($request);
        if ($permissions instanceof JsonResponse) {
            return $permissions;
        }

        if ($permissions === null) {
            return $this->error(
                code: 'validation_failed',
                message: 'Use preset or permissions to specify grant permissions.',
                meta: ['fields' => ['preset', 'permissions']],
                status: 422,
            );
        }

        $normalized = app(NodePermissionNormalizer::class)->normalize($permissions);
        $permissions = $normalized->permissions;

        $isGatewayAdmin = in_array('*', $permissions, true)
            && $this->nodeRoleAssignments->nodeIsGateway($serving);

        if ($isGatewayAdmin && ! $request->force()) {
            return $this->error(
                code: 'validation_failed',
                message: 'Use force to create a gateway-admin grant.',
                meta: ['field' => 'force'],
                status: 422,
            );
        }

        $grant = NodeAccess::query()->firstOrCreate([
            'consumer_node_id' => $consumer->id,
            'serving_node_id' => $serving->id,
        ], [
            'permissions' => $permissions,
        ]);

        $alreadyGranted = ! $grant->wasRecentlyCreated;
        $action = 'granted';

        $warnings = [];

        if ($grant->wasRecentlyCreated) {
            $originalPermissions = $request->permissionsInput() !== null
                ? array_map(trim(...), explode(',', $request->permissionsInput()))
                : app(NodePermissionPresets::class)->permissions($request->preset());

            $normalizedForWarning = app(NodePermissionNormalizer::class)->normalize($originalPermissions);
            if ($normalizedForWarning->removed !== []) {
                $warnings[] = [
                    'code' => 'node.redundant_permissions',
                    'family' => 'node',
                    'message' => 'Redundant permissions were removed: '.implode(', ', $normalizedForWarning->removed).'.',
                    'next_command' => null,
                    'permissions' => $normalizedForWarning->removed,
                ];
            }
        }

        $data = [
            'consuming_node' => $consumer->name,
            'serving_node' => $serving->name,
            'action' => $action,
            'already_granted' => $alreadyGranted,
            'permissions' => $grant->permissions ?? ['*'],
        ];

        $payload = ['success' => ['data' => $data]];

        if ($warnings !== []) {
            $payload['success']['meta'] = ['warnings' => $warnings];
        }

        return response()->json($payload);
    }

    /**
     * @return list<string>|JsonResponse|null
     */
    private function resolvePermissions(GrantNodeApiRequest $request): array|JsonResponse|null
    {
        $preset = $request->preset();
        $permissionsInput = $request->permissionsInput();

        if ($preset === null && $permissionsInput === null) {
            return null;
        }

        if ($preset !== null && $permissionsInput !== null) {
            return $this->error(
                code: 'validation_failed',
                message: 'Use either preset or permissions, not both.',
                meta: ['fields' => ['preset', 'permissions']],
                status: 422,
            );
        }

        if ($preset !== null) {
            try {
                return app(NodePermissionPresets::class)->permissions($preset);
            } catch (InvalidArgumentException $e) {
                return $this->error(
                    code: 'validation_failed',
                    message: $e->getMessage(),
                    meta: ['field' => 'preset', 'preset' => $preset],
                    status: 422,
                );
            }
        }

        if ($permissionsInput !== null) {
            $permissions = array_map(trim(...), explode(',', $permissionsInput));
            $permissions = array_values(array_filter($permissions));

            if ($permissions === []) {
                return $this->error(
                    code: 'validation_failed',
                    message: 'Permission set cannot be empty.',
                    meta: ['field' => 'permissions'],
                    status: 422,
                );
            }

            try {
                app(NodePermissionNormalizer::class)->validate($permissions);
            } catch (InvalidArgumentException $e) {
                return $this->error(
                    code: 'validation_failed',
                    message: $e->getMessage(),
                    meta: ['field' => 'permissions'],
                    status: 422,
                );
            }

            return $permissions;
        }

        return null;
    }

    private function resolveNode(string $name, string $field): Node|JsonResponse
    {
        $node = Node::query()
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
            ->first();

        if ($node instanceof Node) {
            return $node;
        }

        $label = $field === 'consuming_node' ? 'Consuming' : 'Serving';

        return $this->error(
            code: 'node.not_found',
            message: "{$label} node '{$name}' not found.",
            meta: [
                'field' => $field,
                'name' => $name,
            ],
            status: 404,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function authorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return $this->error(
            code: 'authorization_failed',
            message: $message,
            meta: $meta,
            status: 403,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /nodes/grant';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return Node::query()
            ->where('name', (string) request('serving_node'))
            ->first();
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function properties(): array
    {
        return [
            'consuming_node' => (string) request('consuming_node'),
            'serving_node' => (string) request('serving_node'),
        ];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): string
    {
        return sprintf('%s granted access to %s', (string) request('consuming_node'), (string) request('serving_node'));
    }

    public function activityLogDescription(): string
    {
        return $this->description();
    }
}
