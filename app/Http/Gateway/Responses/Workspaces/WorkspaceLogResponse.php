<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Workspaces;

final readonly class WorkspaceLogResponse
{
    /**
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $run,
        public array $meta = [],
    ) {}
}
