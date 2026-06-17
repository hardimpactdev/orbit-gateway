<?php

declare(strict_types=1);

namespace App\Services\Trust;

use Closure;

interface TrustStoreInstaller
{
    public function isCaTrusted(string $rootCaPath, string $label): bool;

    public function trustCa(string $rootCaPath, string $label, ?Closure $log = null): void;
}
