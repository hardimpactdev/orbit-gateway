<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionPresets;
use Illuminate\Database\Eloquent\Builder;

final readonly class RoleSelfGrantMaterializer
{
    public function __construct(
        private NodePermissionPresets $presets,
        private NodePermissionNormalizer $normalizer,
    ) {}

    public function materializeOnRoleApplied(Node $node, NodeRoleName $role): void
    {
        $this->persistEffectiveSelfGrant($node);
    }

    public function reconcileOnRoleRemoved(Node $node, NodeRoleName $role): void
    {
        $this->persistEffectiveSelfGrant($node);
    }

    /**
     * @return list<string>
     */
    public function effectiveSelfPermissions(Node $node): array
    {
        return $this->normalize([
            ...$this->roleDerivedSelfPermissions($node),
            ...$this->customSelfPermissions($node),
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    public function replaceCustomSelfPermissions(Node $node, array $permissions): void
    {
        $customPermissions = $this->normalize($permissions);

        if ($customPermissions === []) {
            $this->selfGrantQuery($node)->delete();

            return;
        }

        NodeAccess::query()->updateOrCreate([
            'consumer_node_id' => $node->id,
            'serving_node_id' => $node->id,
        ], [
            'permissions' => $customPermissions,
            'custom_permissions' => $customPermissions,
        ]);
    }

    /**
     * @return list<string>
     */
    public function roleDerivedSelfPermissions(Node $node): array
    {
        $permissions = [];

        foreach ($this->activeRoleNames($node) as $role) {
            $presetName = $this->presets->selfPresetNameForRole($role);

            if ($presetName === null) {
                continue;
            }

            $permissions = [
                ...$permissions,
                ...$this->presets->permissions($presetName),
            ];
        }

        return $this->normalize($permissions);
    }

    private function persistEffectiveSelfGrant(Node $node): void
    {
        $customPermissions = $this->customSelfPermissions($node);
        $effectivePermissions = $this->effectiveSelfPermissions($node);

        if ($effectivePermissions === [] && $customPermissions === []) {
            $this->selfGrantQuery($node)->delete();

            return;
        }

        NodeAccess::query()->updateOrCreate([
            'consumer_node_id' => $node->id,
            'serving_node_id' => $node->id,
        ], [
            'permissions' => $effectivePermissions,
            'custom_permissions' => $customPermissions,
        ]);
    }

    /**
     * @return list<string>
     */
    private function customSelfPermissions(Node $node): array
    {
        $grant = $this->selfGrantQuery($node)->first();

        if (! $grant instanceof NodeAccess) {
            return [];
        }

        return $this->normalize($grant->custom_permissions ?? []);
    }

    /**
     * @return list<string>
     */
    private function activeRoleNames(Node $node): array
    {
        return $node->roleAssignments()
            ->where('status', NodeRoleStatus::Active->value)
            ->orderBy('role')
            ->pluck('role')
            ->map(fn (mixed $role): string => (string) $role)
            ->all();
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function normalize(array $permissions): array
    {
        return $this->normalizer->normalize($permissions)->permissions;
    }

    /**
     * @return Builder<NodeAccess>
     */
    private function selfGrantQuery(Node $node): Builder
    {
        return NodeAccess::query()
            ->where('consumer_node_id', $node->id)
            ->where('serving_node_id', $node->id);
    }
}
