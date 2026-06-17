<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class WorkspaceRoleGuard
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function ensureAppSupportsWorkspaces(App $app): void
    {
        $app->loadMissing('node');

        $node = $app->node;

        if (! $node instanceof Node) {
            return;
        }

        if (! $this->nodeRoleAssignments->nodeHasActiveRole($node, 'app-prod')) {
            return;
        }

        throw new WorkspaceUnsupportedForProduction([
            'app' => $app->name,
            'node' => $node->name,
            'role' => 'app-prod',
        ]);
    }

    public function ensureWorkspaceSupported(Workspace $workspace): void
    {
        $workspace->loadMissing('app.node');

        if ($workspace->app instanceof App) {
            $this->ensureAppSupportsWorkspaces($workspace->app);
        }
    }
}
