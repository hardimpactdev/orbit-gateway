<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Api\AddNodeRoleApiRequest;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use App\Services\Nodes\Roles\NodeRoleAssignmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

#[RequiresPermission('role:add', servingNode: ServingNode::Target)]
final class NodeRoleAddController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __construct(private readonly NodeRoleAssignmentService $service) {}

    public function __invoke(AddNodeRoleApiRequest $request, string $name): JsonResponse
    {
        if (in_array($request->role(), ['gateway', 'vpn', 'router'], true)) {
            return $this->error(
                'validation_failed',
                "Role '{$request->role()}' is gateway-coupled and cannot be assigned independently.",
                ['field' => 'role', 'role' => $request->role()],
                422,
            );
        }

        $node = Node::query()->where('name', $name)->where('status', NodeStatus::Active->value)->first();
        if (! $node instanceof Node) {
            return $this->error('node.not_found', "Node '{$name}' not found.", ['name' => $name], 404);
        }

        $this->activitySubject = $node;

        $settings = $request->settings();
        $ingressNode = $request->ingressNode();
        $postgresNode = $request->postgresNode();
        $clickhouseNode = $request->clickhouseNode();

        if ($request->role() !== 'app-prod' && $ingressNode !== null) {
            return $this->error(
                'validation_failed',
                "Role '{$request->role()}' does not accept ingress_node.",
                ['field' => 'ingress_node', 'role' => $request->role()],
                422,
            );
        }

        if ($request->role() !== 'analytics' && ($postgresNode !== null || $clickhouseNode !== null)) {
            return $this->error(
                'validation_failed',
                "Role '{$request->role()}' does not accept analytics database nodes.",
                ['field' => $postgresNode !== null ? 'postgres_node' : 'clickhouse_node', 'role' => $request->role()],
                422,
            );
        }

        if ($request->role() === 'app-prod') {
            $settings = $this->resolveAppProductionSettings($node, $ingressNode, $settings);

            if ($settings instanceof JsonResponse) {
                return $settings;
            }
        }

        if ($request->role() === 'analytics') {
            $settings = $this->resolveAnalyticsSettings($settings, $postgresNode, $clickhouseNode);

            if ($settings instanceof JsonResponse) {
                return $settings;
            }
        }

        if ($request->reconvergeExisting() && $request->role() !== 'metrics') {
            return $this->error(
                'validation_failed',
                "Role '{$request->role()}' does not accept reconverge_existing.",
                ['field' => 'reconverge_existing', 'role' => $request->role()],
                422,
            );
        }

        try {
            $assignment = $request->reconvergeExisting() && $this->existingRoleAssigned($node, $request->role())
                ? $this->service->update($node, $request->role(), $settings)
                : $this->service->add($node, $request->role(), $settings);
        } catch (InvalidArgumentException $exception) {
            return $this->error('validation_failed', $exception->getMessage(), ['role' => $request->role()], 422);
        }

        if ($assignment->status === NodeRoleStatus::Error) {
            return $this->error(
                'node_role.convergence_failed',
                "Role '{$request->role()}' convergence failed.",
                [
                    'role' => $request->role(),
                    'status' => $assignment->status->value,
                    'last_error' => $assignment->last_error,
                ],
                500,
            );
        }

        return response()->json([
            'success' => [
                'data' => [
                    'node' => $node->name,
                    'assignment' => NodeRoleAssignmentPayload::fromModel($assignment),
                ],
            ],
        ]);
    }

    private function existingRoleAssigned(Node $node, string $role): bool
    {
        return $node->roleAssignments()
            ->where('role', $role)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|JsonResponse
     */
    private function resolveAppProductionSettings(Node $node, ?string $ingressNodeName, array $settings): array|JsonResponse
    {
        $ingressAssignment = $node->roleAssignments()
            ->where('role', 'ingress')
            ->where('status', NodeRoleStatus::Active->value)
            ->first();

        if ($ingressAssignment instanceof NodeRoleAssignment) {
            if ($ingressNodeName !== null) {
                return $this->error(
                    'validation_failed',
                    'The app-prod role does not accept ingress_node when the target node already hosts ingress.',
                    ['field' => 'ingress_node', 'role' => 'app-prod'],
                    422,
                );
            }

            $settings['ingress_node_id'] = $node->id;

            return $settings;
        }

        if ($ingressNodeName === null) {
            return $this->error(
                'validation_failed',
                'The app-prod role requires an active ingress node.',
                ['field' => 'ingress_node', 'required_role' => 'ingress'],
                422,
            );
        }

        $ingressNode = Node::query()
            ->where('name', $ingressNodeName)
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn ($query) => $query
                ->where('role', 'ingress')
                ->where('status', NodeRoleStatus::Active->value))
            ->first();

        if (! $ingressNode instanceof Node) {
            return $this->error(
                'validation_failed',
                'The app-prod role requires an active ingress node.',
                ['field' => 'ingress_node', 'required_role' => 'ingress'],
                422,
            );
        }

        $settings['ingress_node_id'] = $ingressNode->id;

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|JsonResponse
     */
    private function resolveAnalyticsSettings(array $settings, ?string $postgresNodeName, ?string $clickhouseNodeName): array|JsonResponse
    {
        $postgresNodeName ??= is_string($settings['postgres_node'] ?? null) ? $settings['postgres_node'] : null;
        $clickhouseNodeName ??= is_string($settings['clickhouse_node'] ?? null) ? $settings['clickhouse_node'] : null;

        unset($settings['postgres_node'], $settings['clickhouse_node']);

        if (! array_key_exists('postgres_node_id', $settings)) {
            if ($postgresNodeName === null) {
                return $this->error(
                    'validation_failed',
                    'The analytics role requires an active database node for PostgreSQL.',
                    ['field' => 'postgres_node', 'required_role' => 'database'],
                    422,
                );
            }

            $postgresNode = $this->findActiveDatabaseNodeByName($postgresNodeName);

            if (! $postgresNode instanceof Node) {
                return $this->error(
                    'validation_failed',
                    'The analytics role requires an active database node for PostgreSQL.',
                    ['field' => 'postgres_node', 'required_role' => 'database'],
                    422,
                );
            }

            $settings['postgres_node_id'] = $postgresNode->id;
        }

        if (! array_key_exists('clickhouse_node_id', $settings)) {
            if ($clickhouseNodeName === null) {
                return $this->error(
                    'validation_failed',
                    'The analytics role requires an active database node for ClickHouse.',
                    ['field' => 'clickhouse_node', 'required_role' => 'database'],
                    422,
                );
            }

            $clickhouseNode = $this->findActiveDatabaseNodeByName($clickhouseNodeName);

            if (! $clickhouseNode instanceof Node) {
                return $this->error(
                    'validation_failed',
                    'The analytics role requires an active database node for ClickHouse.',
                    ['field' => 'clickhouse_node', 'required_role' => 'database'],
                    422,
                );
            }

            $settings['clickhouse_node_id'] = $clickhouseNode->id;
        }

        return $settings;
    }

    private function findActiveDatabaseNodeByName(string $name): ?Node
    {
        return Node::query()
            ->where('name', $name)
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn ($query) => $query
                ->where('role', 'database')
                ->where('status', NodeRoleStatus::Active->value))
            ->first();
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
        return 'node.role.added';
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
            'role' => (string) request('role'),
        ];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        $role = (string) request('role');
        $name = (string) request()->route('name');

        return $role !== '' && $name !== '' ? "Role {$role} added to {$name}" : null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
