<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeStatus;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\RemoveNodeRoleApiRequest;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use App\Services\Nodes\Roles\NodeRoleDependencyInspector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Throwable;

#[RequiresPermission('role:remove', servingNode: ServingNode::Target)]
final class NodeRoleRemoveController implements Loggable
{
    private ?Node $activitySubject = null;

    private string $activityAction = 'node.role.removed';

    /**
     * @var list<string>
     */
    private array $activityDependents = [];

    public function __construct(
        private readonly NodeRoleAssignmentService $service,
        private readonly NodeRoleDependencyInspector $dependencyInspector,
    ) {}

    public function __invoke(RemoveNodeRoleApiRequest $request, string $name, string $role): JsonResponse
    {
        $node = Node::query()->where('name', $name)->where('status', NodeStatus::Active->value)->first();
        if (! $node instanceof Node) {
            return $this->error('node.not_found', "Node '{$name}' not found.", ['name' => $name], 404);
        }

        $this->activitySubject = $node;

        if ($request->purgeData() && ! $request->force()) {
            return $this->error('validation_failed', 'The purge-data option requires --force.', ['field' => 'purge_data'], 422);
        }

        if (in_array($role, ['gateway', 'vpn', 'router'], true)) {
            return $this->error('validation_failed', "Role '{$role}' is gateway-coupled and cannot be assigned independently.", ['field' => 'role', 'role' => $role], 422);
        }

        $assignment = NodeRoleAssignment::query()
            ->where('node_id', $node->id)
            ->where('role', $role)
            ->first();

        if (! $assignment instanceof NodeRoleAssignment) {
            return $this->error('validation_failed', "Role '{$role}' is not assigned to node '{$name}'.", ['role' => $role], 422);
        }

        $dependents = $this->dependencyInspector->dependentSummaries($node, $assignment);

        if ($dependents !== [] && ! $request->force()) {
            $this->activityAction = 'node.role.remove_blocked';
            $this->activityDependents = $dependents;

            return $this->error(
                'node_role.remove_blocked',
                "Role '{$role}' cannot be removed while dependents exist.",
                ['role' => $role, 'dependents' => $dependents],
                422,
            );
        }

        try {
            $this->service->remove($node, $role, $request->force(), $request->purgeData());
        } catch (InvalidArgumentException $exception) {
            return $this->error('validation_failed', $exception->getMessage(), ['role' => $role], 422);
        } catch (Throwable $exception) {
            $this->activityAction = 'node.role.remove_failed';

            return $this->error(
                'node_role.remove_failed',
                "Role '{$role}' could not be removed.",
                ['role' => $role, 'last_error' => $exception->getMessage()],
                500,
            );
        }

        return response()->json([
            'success' => [
                'data' => [
                    'node' => $node->name,
                    'role' => $role,
                    'purged_data' => $request->purgeData(),
                ],
            ],
        ]);
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
        return ActivityLogType::Destructive;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return $this->activityAction;
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function properties(): array
    {
        return [
            'node' => (string) request()->route('name'),
            'role' => (string) request()->route('role'),
            'force' => (bool) request()->boolean('force'),
            'purge_data' => (bool) request()->boolean('purge_data'),
            'dependents' => $this->activityDependents,
        ];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        $role = (string) request()->route('role');
        $name = (string) request()->route('name');

        return $role !== '' && $name !== '' ? "Role {$role} removed from {$name}" : null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
