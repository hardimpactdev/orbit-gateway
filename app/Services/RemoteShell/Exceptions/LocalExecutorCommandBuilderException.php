<?php

declare(strict_types=1);

namespace App\Services\RemoteShell\Exceptions;

use RuntimeException;

class LocalExecutorCommandBuilderException extends RuntimeException
{
    public static function invalidCommandName(): self
    {
        return new self('Local executor command name is invalid.');
    }

    public static function commandNotAllowed(string $commandName): self
    {
        return new self("Local executor command [{$commandName}] is not allowed for the target node roles.");
    }

    public static function invalidArgument(): self
    {
        return new self('Local executor arguments must be scalar values.');
    }

    public static function invalidOptionKey(): self
    {
        return new self('Local executor option key is invalid.');
    }

    public static function invalidOptionValue(string $key): self
    {
        return new self("Local executor option [{$key}] must be a scalar value.");
    }

    public static function invalidOperationToken(): self
    {
        return new self('Local executor operation token is required.');
    }

    public static function nullByte(string $field): self
    {
        return new self("Local executor {$field} contains a null byte.");
    }
}
