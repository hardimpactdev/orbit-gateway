<?php

declare(strict_types=1);

namespace App\Services\Nodes\Access;

use App\Data\Nodes\NodeAccessPermissions;
use InvalidArgumentException;

final readonly class NodePermissionNormalizer
{
    public function __construct(
        private NodePermissionRegistry $registry,
    ) {}

    /**
     * Normalize a permission set.
     *
     * Removes duplicate permissions, removes permissions that are implied by
     * another permission in the set, and validates that all permissions are
     * registry-known.
     *
     * @param  list<string>  $permissions
     *
     * @throws InvalidArgumentException when an unknown permission is present.
     */
    public function normalize(array $permissions): NodeAccessPermissions
    {
        $this->validate($permissions);

        $unique = array_values(array_unique($permissions));

        // Sort to ensure deterministic output.
        sort($unique);

        $kept = [];
        $removed = [];

        foreach ($unique as $permission) {
            $covered = false;

            foreach ($kept as $keptPermission) {
                if ($this->registry->isCoveredBy($permission, $keptPermission)) {
                    $covered = true;
                    $removed[] = $permission;

                    break;
                }
            }

            if (! $covered) {
                // Check if this permission covers any already-kept permissions.
                // If so, remove the covered ones.
                $newKept = [];

                foreach ($kept as $keptPermission) {
                    if ($this->registry->isCoveredBy($keptPermission, $permission)) {
                        $removed[] = $keptPermission;
                    } else {
                        $newKept[] = $keptPermission;
                    }
                }

                $kept = $newKept;
                $kept[] = $permission;
            }
        }

        sort($kept);
        sort($removed);

        return new NodeAccessPermissions(
            permissions: $kept,
            removed: array_values(array_unique($removed)),
        );
    }

    /**
     * Validate that all permissions are known.
     *
     * @param  list<string>  $permissions
     *
     * @throws InvalidArgumentException when an unknown permission is present.
     */
    public function validate(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (! $this->registry->isKnown($permission)) {
                throw new InvalidArgumentException("Unknown permission [{$permission}].");
            }
        }
    }
}
