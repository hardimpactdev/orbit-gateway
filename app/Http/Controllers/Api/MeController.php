<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

final readonly class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Node $self */
        $self = $request->user();
        $self->loadMissing('roleAssignments');

        $gateway = app(NodeRoleAssignments::class)
            ->activeGatewayNodeQuery()
            ->with('roleAssignments')
            ->orderBy('name')
            ->first();

        return response()->json([
            'success' => [
                'data' => [
                    'self' => $this->serialize($self),
                    'gateway' => $gateway instanceof Node ? $this->serialize($gateway) : null,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Node $node): array
    {
        return [
            'name' => $node->name,
            'status' => $node->status->value,
            'platform' => $node->platform ?? 'unknown',
            'roles' => $node->roleAssignments->map(fn (NodeRoleAssignment $assignment): array => [
                'role' => $assignment->role,
                'status' => $assignment->status->value,
                'settings' => $this->normalizeRoleSettings($assignment->settings),
            ])->all(),
            'addresses' => [
                'wireguard' => $node->wireguard_address,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    private function normalizeRoleSettings(mixed $settings): array|stdClass
    {
        return NodeRoleAssignmentPayload::settings($settings);
    }
}
