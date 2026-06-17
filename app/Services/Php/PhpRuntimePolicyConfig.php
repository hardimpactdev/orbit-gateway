<?php

declare(strict_types=1);

namespace App\Services\Php;

final readonly class PhpRuntimePolicyConfig
{
    /**
     * @param  array<string, string>  $phpIni
     */
    public function __construct(
        public string $phpVersion,
        public string $image,
        public string $mode,
        public array $phpIni,
    ) {}
}
