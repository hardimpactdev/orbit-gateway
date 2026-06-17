<?php

declare(strict_types=1);

namespace App\Services\Updates;

final readonly class UpdateDriverTarget
{
    public function __construct(
        public string $family,
        public ?string $platform,
        public ?string $scope,
    ) {}
}
