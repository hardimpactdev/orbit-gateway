<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkspaceLifecyclePhase: string
{
    case Setup = 'setup';
    case Teardown = 'teardown';
}
