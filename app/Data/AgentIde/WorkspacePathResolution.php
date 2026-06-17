<?php

declare(strict_types=1);

namespace App\Data\AgentIde;

final readonly class WorkspacePathResolution
{
    public function __construct(
        public string $workspaceName,
        public string $appSlug,
        public string $path,
        public string $adapterWorkspaceId,
    ) {}
}
