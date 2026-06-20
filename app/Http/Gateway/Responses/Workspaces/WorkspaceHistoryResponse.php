<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Workspaces;

final readonly class WorkspaceHistoryResponse
{
    /**
     * @param  list<array<string, mixed>>  $runs
     * @param  array<string, mixed>  $pagination
     */
    public function __construct(
        public array $runs,
        public array $pagination,
    ) {}
}
