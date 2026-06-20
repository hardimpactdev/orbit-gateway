<?php

declare(strict_types=1);

namespace App\Data\Workspaces;

final readonly class WorkspaceProvisionResult
{
    public function __construct(
        public string $name,
        public string $path,
        public ?string $agentIde = null,
        public ?string $agentIdeWorkspaceId = null,
    ) {}
}
