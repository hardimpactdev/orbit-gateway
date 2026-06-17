<?php

declare(strict_types=1);

namespace App\Enums\Workspaces;

enum WorkspaceRuntimeContainerApplyOutcome: string
{
    case Created = 'created';
    case Recreated = 'recreated';
    case Started = 'started';
    case Unchanged = 'unchanged';
}
