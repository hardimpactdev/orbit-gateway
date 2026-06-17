<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Models\App;
use App\Models\Node;

interface WorkspaceSourceDriver
{
    public function create(App $app, Node $node, string $name, string $base): WorkspaceProvisionResult;
}
