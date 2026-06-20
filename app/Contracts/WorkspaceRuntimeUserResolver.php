<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Workspace;

interface WorkspaceRuntimeUserResolver
{
    public function forWorkspace(Workspace $workspace): string;
}
