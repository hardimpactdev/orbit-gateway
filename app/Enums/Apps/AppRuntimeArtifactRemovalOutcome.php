<?php

declare(strict_types=1);

namespace App\Enums\Apps;

/**
 * Tri-state outcome for removing a managed app runtime artifact (the
 * FrankenPHP container or its mounted runtime config file). Cleanup payloads
 * and warnings depend on whether the artifact existed in the first place:
 * removing something is different from "nothing to remove" which is different
 * from "tried but the artifact remains".
 */
enum AppRuntimeArtifactRemovalOutcome: string
{
    case Removed = 'removed';
    case AlreadyAbsent = 'already_absent';
    case FailedRemaining = 'failed_remaining';
}
