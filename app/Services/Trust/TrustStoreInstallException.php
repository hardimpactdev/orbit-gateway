<?php

declare(strict_types=1);

namespace App\Services\Trust;

use App\Enums\Trust\TrustStoreInstallReason;
use RuntimeException;

final class TrustStoreInstallException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly TrustStoreInstallReason $reason,
    ) {
        parent::__construct($message);
    }
}
