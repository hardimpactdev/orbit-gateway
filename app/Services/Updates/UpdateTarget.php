<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Models\Node;

final readonly class UpdateTarget
{
    public function __construct(
        public string $family,
        public Node $node,
        public ?string $platform,
        public string $scope,
    ) {}
}
