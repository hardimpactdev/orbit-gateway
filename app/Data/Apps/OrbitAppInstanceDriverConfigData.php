<?php

declare(strict_types=1);

namespace App\Data\Apps;

final class OrbitAppInstanceDriverConfigData extends AppInstanceDriverConfigData
{
    public function __construct(
        public ?int $node_id = null,
        public ?string $node = null,
        public ?string $path = null,
        public ?string $document_root = null,
        public ?string $domain = null,
    ) {}
}
