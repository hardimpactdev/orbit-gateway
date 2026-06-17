<?php

declare(strict_types=1);

namespace App\Services\RemoteShell\Exceptions;

use RuntimeException;

class HostKeyPinningFailed extends RuntimeException
{
    public static function forHost(string $host, string $reason): self
    {
        return new self("Could not pin SSH host key for [{$host}]: {$reason}");
    }
}
