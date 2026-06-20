<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\AgentIde\WorkspacePathResolution;
use App\Models\App;

interface AgentIdeWorkspacePathResolver
{
    public function resolve(string $adapter, App $app, string $absolutePath): ?WorkspacePathResolution;
}
