<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Firewall;

final readonly class FirewallRuleListResponse
{
    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $rules,
        public array $meta,
    ) {}
}
