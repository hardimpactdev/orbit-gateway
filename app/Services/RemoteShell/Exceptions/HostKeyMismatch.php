<?php

declare(strict_types=1);

namespace App\Services\RemoteShell\Exceptions;

use RuntimeException;

class HostKeyMismatch extends RuntimeException
{
    public static function forHost(string $host, string $expected, string $actual): self
    {
        return new self("SSH host key fingerprint mismatch for [{$host}]. Expected [{$expected}], observed [{$actual}].");
    }
}
