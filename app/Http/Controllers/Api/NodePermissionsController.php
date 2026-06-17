<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeStatus;
use App\Http\Requests\Api\NodePermissionsApiRequest;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionPresets;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

final readonly class NodePermissionsController implements Loggable
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $authorizer,
    ) {}

    private function requiredPermission(NodePermissionsApiRequest $request): string
    {
        return $this->isReadMode($request) ? 'node:read' : 'node:permissions';
    }

    public function __invoke(NodePermissionsApiRequest $request): JsonResponse
    {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->authorizationFailed('Peer identity unknown.');
        }

        $authorization = $this->authorizeCaller($caller, $this->requiredPermission($request));

        if ($authorization instanceof JsonResponse) {
            return $authorization;
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

        $preset = $request->preset();
        $permissionsOpt = $request->permissionsInput();
        $addOpt = $request->addInput();
        $removeOpt = $request->removeInput();

        $modeCount = $this->modeCount($request);

        if ($modeCount > 1) {
            return $this->error(
                code: 'validation_failed',
                message: 'Use only one of preset, permissions, add, or remove.',
                meta: [],
                status: 422,
            );
        }

        $isReadMode = $modeCount === 0;

        $grant = NodeAccess::query()
            ->where('consumer_node_id', $consumer->id)
            ->where('serving_node_id', $serving->id)
            ->first();

        if ($isReadMode) {
            if ($grant === null) {
                return $this->grantNotFound($consumerName, $servingName);
            }

            return $this->success([
                'consuming_node' => $consumerName,
                'serving_node' => $servingName,
                'action' => 'read',
                'mode' => 'read',
                'permissions' => $grant->permissions ?? ['*'],
            ]);
        }

        if ($removeOpt !== null) {
            if ($grant === null) {
                return $this->grantNotFound($consumerName, $servingName);
            }

            $toRemove = array_map(trim(...), explode(',', $removeOpt));
            $toRemove = array_values(array_filter($toRemove));

            try {
                app(NodePermissionNormalizer::class)->validate($toRemove);
            } catch (InvalidArgumentException $e) {
                return $this->error(
                    code: 'validation_failed',
                    message: $e->getMessage(),
                    meta: ['field' => 'remove'],
                    status: 422,
                );
            }

            $currentPermissions = $grant->permissions ?? ['*'];
            $newPermissions = array_values(array_diff($currentPermissions, $toRemove));

            if ($newPermissions === []) {
                $newPermissions = [];
            }

            $normalized = app(NodePermissionNormalizer::class)->normalize($newPermissions);
            $newPermissions = $normalized->permissions;

            $grant->update(['permissions' => $newPermissions]);

            $data = [
                'consuming_node' => $consumerName,
                'serving_node' => $servingName,
                'action' => 'updated',
                'mode' => 'remove',
                'permissions' => $newPermissions,
            ];

            $payload = ['success' => ['data' => $data]];

            if ($normalized->removed !== []) {
                $payload['success']['meta'] = ['warnings' => [
                    [
                        'code' => 'node.redundant_permissions',
                        'family' => 'node',
                        'message' => 'Redundant permissions were removed: '.implode(', ', $normalized->removed).'.',
                        'next_command' => null,
                        'permissions' => $normalized->removed,
                    ],
                ]];
            }

            return response()->json($payload);
        }

        $permissions = $this->resolveMutationPermissions($preset, $permissionsOpt, $addOpt, $grant);
        if ($permissions instanceof JsonResponse) {
            return $permissions;
        }

        $normalized = app(NodePermissionNormalizer::class)->normalize($permissions);

        $mode = $preset !== null ? 'preset' : ($permissionsOpt !== null ? 'permissions' : 'add');

        $warnings = [];

        if ($normalized->removed !== []) {
            $warnings[] = [
                'code' => 'node.redundant_permissions',
                'family' => 'node',
                'message' => 'Redundant permissions were removed: '.implode(', ', $normalized->removed).'.',
                'next_command' => null,
                'permissions' => $normalized->removed,
            ];
        }

        if ($grant === null) {
            NodeAccess::query()->create([
                'consumer_node_id' => $consumer->id,
                'serving_node_id' => $serving->id,
                'permissions' => $normalized->permissions,
            ]);

            $data = [
                'consuming_node' => $consumerName,
                'serving_node' => $servingName,
                'action' => 'created',
                'mode' => $mode,
                'permissions' => $normalized->permissions,
            ];
        } else {
            $grant->update(['permissions' => $normalized->permissions]);

            $data = [
                'consuming_node' => $consumerName,
                'serving_node' => $servingName,
                'action' => 'updated',
                'mode' => $mode,
                'permissions' => $normalized->permissions,
            ];
        }

        $payload = ['success' => ['data' => $data]];

        if ($warnings !== []) {
            $payload['success']['meta'] = ['warnings' => $warnings];
        }

        return response()->json($payload);
    }

    private function authorizeCaller(Node $caller, string $permission): ?JsonResponse
    {
        $gateway = $this->nodeRoleAssignments->activeGatewayNodeQuery()->first();

        if (! $gateway instanceof Node) {
            return $this->authorizationFailed(
                'This action requires a grant to the gateway.',
                [
                    'reason' => 'serving_node_unresolved',
                    'missing_permission' => $permission,
                ],
            );
        }

        $result = $this->authorizer->authorize($caller, $gateway, $permission);

        if ($result->allowed) {
            return null;
        }

        return $this->authorizationFailed(
            "This action requires the {$permission} permission on a grant to the gateway.",
            [
                'reason' => $result->reason,
                'missing_permission' => $result->missingPermission,
                'serving_node' => $gateway->name,
            ],
        );
    }

    private function isReadMode(NodePermissionsApiRequest $request): bool
    {
        return $this->modeCount($request) === 0;
    }

    private function modeCount(NodePermissionsApiRequest $request): int
    {
        return (int) ($request->preset() !== null)
            + (int) ($request->permissionsInput() !== null)
            + (int) ($request->addInput() !== null)
            + (int) ($request->removeInput() !== null);
    }

    /**
     * @return list<string>|JsonResponse
     */
    private function resolveMutationPermissions(?string $preset, ?string $permissionsOpt, ?string $addOpt, ?NodeAccess $grant): array|JsonResponse
    {
        if ($preset !== null) {
            if ($preset === '') {
                return $this->error(
                    code: 'validation_failed',
                    message: 'Preset cannot be empty.',
                    meta: ['field' => 'preset'],
                    status: 422,
                );
            }

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

        if ($permissionsOpt !== null) {
            $permissions = array_map(trim(...), explode(',', $permissionsOpt));
            $permissions = array_values(array_filter($permissions));

            if ($permissions === []) {
                return $this->error(
                    code: 'validation_failed',
                    message: 'Permissions cannot be empty.',
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

        if ($addOpt !== null) {
            $toAdd = array_map(trim(...), explode(',', $addOpt));
            $toAdd = array_values(array_filter($toAdd));

            if ($toAdd === []) {
                return $this->error(
                    code: 'validation_failed',
                    message: 'Add permissions cannot be empty.',
                    meta: ['field' => 'add'],
                    status: 422,
                );
            }

            try {
                app(NodePermissionNormalizer::class)->validate($toAdd);
            } catch (InvalidArgumentException $e) {
                return $this->error(
                    code: 'validation_failed',
                    message: $e->getMessage(),
                    meta: ['field' => 'add'],
                    status: 422,
                );
            }

            $currentPermissions = $grant !== null ? ($grant->permissions ?? ['*']) : [];
            $merged = array_values(array_unique(array_merge($currentPermissions, $toAdd)));

            return $merged;
        }

        return [];
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

    private function grantNotFound(string $consumerName, string $servingName): JsonResponse
    {
        return $this->error(
            code: 'node.grant_not_found',
            message: "Grant from '{$consumerName}' to '{$servingName}' not found.",
            meta: [
                'consuming_node' => $consumerName,
                'serving_node' => $servingName,
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function success(array $data): JsonResponse
    {
        return response()->json([
            'success' => [
                'data' => $data,
            ],
        ]);
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
        return 'api:POST /nodes/permissions';
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
        $mode = 'read';
        foreach (['preset', 'permissions', 'add', 'remove'] as $field) {
            $value = request($field);
            if (is_string($value) && $value !== '') {
                $mode = $value;
                break;
            }
        }

        return sprintf(
            '%s permissions updated for %s -> %s',
            $mode,
            (string) request('consuming_node'),
            (string) request('serving_node'),
        );
    }

    public function activityLogDescription(): string
    {
        return $this->description();
    }
}
