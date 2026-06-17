<?php

declare(strict_types=1);

namespace App\Services\RemoteShell\Exceptions;

use RuntimeException;

class HostKeyMissing extends RuntimeException
{
    public static function forNode(string $node): self
    {
        return new self("Pinned SSH host key material is missing for node [{$node}].");
    }
}
