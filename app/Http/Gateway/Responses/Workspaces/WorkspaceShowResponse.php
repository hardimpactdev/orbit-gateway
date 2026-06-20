<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Workspaces;

final readonly class WorkspaceShowResponse
{
    /**
     * @param  array<string, mixed>  $workspace
     */
    public function __construct(
        public array $workspace,
    ) {}
}
