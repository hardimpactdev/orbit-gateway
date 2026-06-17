<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\NodesDoctorSummary;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class NodeListController implements Loggable
{
    private const array VALID_ROLES = ['gateway', 'vpn', 'router', 'app-dev', 'app-prod', 'database', 'agent', 'ingress', 'websocket', 's3'];

    public function __invoke(Request $request, NodesDoctorSummary $doctorSummary): JsonResponse
    {
        $role = $request->query('role');
        $environment = $request->query('environment');
        $doctor = (bool) filter_var($request->query('doctor', false), FILTER_VALIDATE_BOOLEAN);

        if ($environment !== null) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Node environment filters are not supported. Filter by role instead.',
                    'meta' => [
                        'field' => 'environment',
                        'reason' => 'unsupported_field',
                    ],
                ],
            ], 400);
        }

        if (is_string($role) && $role !== '') {
            if (! in_array($role, self::VALID_ROLES, true)) {
                return response()->json([
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => "Invalid value for role: '{$role}'. Allowed values: ".implode(', ', self::VALID_ROLES).'.',
                        'meta' => [
                            'field' => 'role',
                            'value' => $role,
                            'allowed' => self::VALID_ROLES,
                        ],
                    ],
                ], 400);
            }
        }

        $nodes = $this->fetchNodeModels(
            role: is_string($role) && $role !== '' ? $role : null,
        );

        $success = [
            'data' => [
                'nodes' => $this->nodePayloads($nodes),
            ],
        ];

        if ($doctor) {
            $success['meta'] = [
                'doctor' => $doctorSummary->forNodes($nodes),
            ];
        }

        return response()->json([
            'success' => $success,
        ]);
    }

    /**
     * @return Collection<int, Node>
     */
    private function fetchNodeModels(?string $role): Collection
    {
        $query = Node::query()
            ->with('roleAssignments');

        if ($role !== null) {
            $this->applyRoleFilter($query, $role);
        }

        $assignments = app(NodeRoleAssignments::class);

        return $query
            ->get()
            ->sort(fn (Node $first, Node $second): int => [
                $assignments->assignmentRoleLabel($first),
                mb_strtolower($first->name),
            ] <=> [
                $assignments->assignmentRoleLabel($second),
                mb_strtolower($second->name),
            ])
            ->values();
    }

    /**
     * @param  Builder<Node>  $query
     */
    private function applyRoleFilter(Builder $query, string $role): void
    {
        $assignments = app(NodeRoleAssignments::class);

        $query->whereIn('id', $assignments->activeNodeIdsForRole($role));
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return list<array<string, mixed>>
     */
    private function nodePayloads(Collection $nodes): array
    {
        return $nodes->map(fn (Node $node): array => [
            'name' => $node->name,
            'host' => $node->host,
            'addresses' => [
                'wireguard' => $node->wireguard_address,
            ],
            'platform' => $node->platform ?? 'unknown',
            'status' => $node->status->value,
            'roles' => $node->roleAssignments
                ->map(fn (NodeRoleAssignment $assignment): array => NodeRoleAssignmentPayload::fromModel($assignment))
                ->all(),
        ])->all();
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Read;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:GET /nodes';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    public function properties(): array
    {
        return [];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
