<?php

declare(strict_types=1);

namespace App\Data\AgentIde;

final readonly class OpenCodeServerConfig
{
    public function __construct(
        public string $url,
        public ?string $username = null,
        public ?string $password = null,
    ) {}
}
