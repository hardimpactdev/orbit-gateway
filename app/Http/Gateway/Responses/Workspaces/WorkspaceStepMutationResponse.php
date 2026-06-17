<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Workspaces;

final readonly class WorkspaceStepMutationResponse
{
    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $result,
        public array $step,
        public array $meta = [],
    ) {}
}
