<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Workspaces;

final readonly class WorkspaceStepListResponse
{
    /**
     * @param  list<array<string, mixed>>  $steps
     */
    public function __construct(
        public array $steps,
    ) {}
}
