<?php

declare(strict_types=1);

namespace App\Data\Convergence;

final readonly class UfwFirewallRuleProbe
{
    /**
     * @param  array<string, mixed>|null  $partialMatch
     */
    public function __construct(
        public bool $reachable,
        public bool $present,
        public ?array $partialMatch = null,
        public ?string $error = null,
    ) {}
}
