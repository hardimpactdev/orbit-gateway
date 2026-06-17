<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Models\App;
use App\Models\Node;

final readonly class CreateWorkspaceProgress
{
    public function __construct(
        private CreateWorkspace $createWorkspace,
        private SetupWorkspace $setupWorkspace,
    ) {}

    public function for(App $app, Node $node, string $name, string $base, ?string $phpVersion): CreateWorkspaceProgressPlan
    {
        return new CreateWorkspaceProgressPlan(
            createWorkspace: $this->createWorkspace,
            setupWorkspace: $this->setupWorkspace,
            app: $app,
            node: $node,
            name: $name,
            base: $base,
            phpVersion: $phpVersion,
        );
    }
}
