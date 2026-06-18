<?php

declare(strict_types=1);

namespace App\Services;

class OrbitHostInstallResult
{
    public function __construct(
        public bool $successful,
        public string $output = '',
        public string $errorOutput = '',
    ) {}
}
