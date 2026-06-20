<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Models\NodeRoleAssignment;
use stdClass;

final class NodeRoleAssignmentPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function fromModel(NodeRoleAssignment $assignment): array
    {
        return [
            'role' => $assignment->role,
            'status' => $assignment->status->value,
            'settings' => self::settings($assignment->settings),
            'last_error' => $assignment->last_error,
            'converged_at' => $assignment->converged_at?->toJSON(),
        ];
    }

    /**
     * @param  array<string, mixed>  $assignment
     * @return array<string, mixed>
     */
    public static function fromArray(array $assignment): array
    {
        return [
            'role' => is_string($assignment['role'] ?? null) ? $assignment['role'] : '',
            'status' => is_string($assignment['status'] ?? null) ? $assignment['status'] : '',
            'settings' => self::settings($assignment['settings'] ?? []),
            'last_error' => is_string($assignment['last_error'] ?? null) ? $assignment['last_error'] : null,
            'converged_at' => is_string($assignment['converged_at'] ?? null) ? $assignment['converged_at'] : null,
        ];
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    public static function settings(mixed $settings): array|stdClass
    {
        if (! is_array($settings) || $settings === []) {
            return (object) [];
        }

        return $settings;
    }
}
