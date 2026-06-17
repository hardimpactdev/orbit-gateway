<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Workspaces;

final readonly class WorkspaceListResponse
{
    /**
     * @param  list<array<string, mixed>>  $workspaces
     */
    public function __construct(
        public array $workspaces,
    ) {}
}
