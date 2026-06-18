<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Node;

final readonly class DoctorScopeValidator
{
    /**
     * @param  list<string>  $families
     */
    public function validate(array $families, DoctorReportRunner $runner, ?Node $target = null): ?DoctorValidationFailure
    {
        foreach ($families as $family) {
            if (! in_array($family, $runner->supportedFamilies(), true)) {
                return new DoctorValidationFailure(
                    code: 'scope_not_found',
                    message: "Doctor family '{$family}' is not available yet.",
                    meta: ['family' => $family],
                );
            }
        }

        if ($target instanceof Node) {
            $targetRole = $target->displayRole();
            $allowed = $runner->categoriesForNode($target);

            foreach ($families as $family) {
                if (! in_array($family, $allowed, true)) {
                    return new DoctorValidationFailure(
                        code: 'family_not_in_role_scope',
                        message: "Doctor family '{$family}' is not part of the '{$targetRole}' node category set.",
                        meta: [
                            'family' => $family,
                            'target_role' => $targetRole,
                            'allowed_families' => $allowed,
                        ],
                    );
                }
            }
        }

        return null;
    }
}
