<?php

declare(strict_types=1);

namespace App\Support;

class LocalPlatform
{
    public function current(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'macos',
            'Linux' => 'linux',
            default => 'unsupported',
        };
    }
}
