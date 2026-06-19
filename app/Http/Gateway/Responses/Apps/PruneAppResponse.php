<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Apps;

final readonly class PruneAppResponse
{
    /**
     * @param  list<array<string, mixed>>  $staleWorkspaces
     * @param  list<array<string, string>>  $warnings
     */
    public function __construct(
        public string $app,
        public array $staleWorkspaces,
        public array $warnings,
        public bool $dryRun,
    ) {}
}
