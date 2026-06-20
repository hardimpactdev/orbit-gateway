<?php

declare(strict_types=1);

namespace App\Data\Convergence;

final readonly class ProcessDockerContainerProbe
{
    /**
     * @param  array<string, mixed>|null  $inspection
     */
    public function __construct(
        public bool $reachable,
        public bool $exists,
        public ?string $specHash = null,
        public ?array $inspection = null,
        public ?string $error = null,
    ) {}
}
