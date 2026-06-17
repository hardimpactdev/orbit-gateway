<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Workspaces;

final readonly class CreateWorkspaceResponse
{
    public function __construct(
        public string $name,
        public string $app,
        public ?string $node,
        public ?string $path,
        public ?string $url,
        public ?string $phpVersion,
        public bool $phpInherited,
        /** @var array<string, mixed> */
        public array $agentIde,
        public bool $adopted,
        public string $lifecycleStatus,
        public string $base,
        public string $action,
        /** @var array<string, mixed> */
        public array $httpProbe,
        /** @var list<array<string, string>> */
        public array $warnings,
    ) {}
}
