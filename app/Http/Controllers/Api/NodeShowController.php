<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\NodeAgentIdeDefaults;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

final class NodeShowController implements Loggable
{
    private ?Node $activitySubject = null;

    public function __invoke(string $name): JsonResponse
    {
        $node = Node::query()
            ->with(['roleAssignments', 'consumingNodes', 'servingNodes'])
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (! $node instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'node.not_found',
                    'message' => "Node '{$name}' not found or not visible.",
                    'meta' => [
                        'name' => $name,
                    ],
                ],
            ], 404);
        }

        $this->activitySubject = $node;

        return response()->json([
            'success' => [
                'data' => [
                    'node' => [
                        'name' => $node->name,
                        'status' => $node->status->value,
                        'platform' => $node->platform ?? 'unknown',
                        'roles' => $node->roleAssignments
                            ->map(fn (NodeRoleAssignment $assignment): array => NodeRoleAssignmentPayload::fromModel($assignment))
                            ->all(),
                        'addresses' => [
                            'wireguard' => $node->wireguard_address,
                        ],
                        'agent_ide' => NodeAgentIdeDefaults::payloadFor($node),
                        'grants' => [
                            'consuming_nodes' => $this->grantNodes($node->consumingNodes),
                            'serving_nodes' => $this->grantNodes($node->servingNodes),
                        ],
                    ],
                ],
            ],
        ]);
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
        return 'api:GET /nodes/{name}';
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
        return [];
    }

    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    /**
     * @param  iterable<int, Node>  $nodes
     * @return list<array{name: string, permissions: list<string>}>
     */
    private function grantNodes(iterable $nodes): array
    {
        $grants = [];

        foreach ($nodes as $node) {
            $grants[] = [
                'name' => $node->name,
                'permissions' => $this->decodePermissions($node->pivot->permissions ?? null),
            ];
        }

        return $grants;
    }

    /**
     * @return list<string>
     */
    private function decodePermissions(mixed $permissions): array
    {
        if ($permissions === null) {
            return ['*'];
        }

        if (is_array($permissions)) {
            return array_values(array_filter($permissions, is_string(...)));
        }

        if (is_string($permissions)) {
            $decoded = json_decode($permissions, associative: true);

            return is_array($decoded)
                ? array_values(array_filter($decoded, is_string(...)))
                : ['*'];
        }

        return ['*'];
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
