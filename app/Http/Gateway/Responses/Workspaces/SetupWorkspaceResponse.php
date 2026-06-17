<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Workspaces;

final readonly class SetupWorkspaceResponse
{
    /**
     * @param  list<array<string, string>>  $warnings
     * @param  array<string, mixed>  $setupSteps
     * @param  array<string, mixed>  $processes
     * @param  array<string, mixed>  $httpProbe
     */
    public function __construct(
        public string $app,
        public string $workspace,
        public string $node,
        public string $url,
        public string $action,
        public array $warnings,
        public array $setupSteps,
        public array $processes,
        public array $httpProbe,
    ) {}
}
