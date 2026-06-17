<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Models\Node;
use App\Services\RemoteShell\Exceptions\LocalExecutorCommandBuilderException;

final readonly class LocalExecutorCommandBuilder
{
    private const string ORBIT_BINARY = '/usr/local/bin/orbit';

    private const string COMMAND_NAME_PATTERN = '/\Ainternal:[a-z0-9:_-]+\z/';

    private const string OPTION_KEY_PATTERN = '/\A[a-z][a-z0-9-]*\z/';

    private const array ALLOWED_COMMAND_ROLES = [
        'internal:executor:verify' => ['gateway', 'vpn', 'router', 'app-dev', 'app-prod', 'database', 'agent', 'ingress'],
        'internal:wg-easy:state' => ['vpn'],
        'internal:database-query-local' => ['app-dev', 'app-prod', 'database'],
        'internal:workspace-adapter:lookup' => ['app-dev'],
        'internal:workspace-adapter:update' => ['app-dev'],
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function allowedCommandRoles(): array
    {
        return self::ALLOWED_COMMAND_ROLES;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     */
    public function build(Node $targetNode, string $commandName, array $arguments, array $options, string $operationToken): string
    {
        return $this->compose(
            targetNode: $targetNode,
            commandName: $commandName,
            arguments: $arguments,
            options: $options,
            operationToken: $operationToken,
            redactOperationToken: false,
        );
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     */
    public function buildAuditLine(Node $targetNode, string $commandName, array $arguments, array $options, string $operationToken): string
    {
        return $this->compose(
            targetNode: $targetNode,
            commandName: $commandName,
            arguments: $arguments,
            options: $options,
            operationToken: $operationToken,
            redactOperationToken: true,
        );
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $options
     */
    private function compose(
        Node $targetNode,
        string $commandName,
        array $arguments,
        array $options,
        string $operationToken,
        bool $redactOperationToken,
    ): string {
        $this->ensureCommandNameIsValid($commandName);
        $this->ensureCommandIsAllowedForTarget($commandName, $targetNode);
        $this->ensureOperationTokenIsValid($operationToken);

        $segments = [
            $this->orbitBinarySegment(),
            $commandName,
            ...$this->argumentSegments($arguments),
            ...$this->optionSegments($options),
            '--operation-token='.($redactOperationToken ? '<redacted>' : escapeshellarg($operationToken)),
            '--json',
        ];

        return implode(' ', $segments);
    }

    private function orbitBinarySegment(): string
    {
        $configuredBinary = config('orbit.local_executor_binary');
        $binary = is_string($configuredBinary) && trim($configuredBinary) !== ''
            ? trim($configuredBinary)
            : self::ORBIT_BINARY;

        $this->ensureNoNullByte($binary, 'orbit binary');

        if ($binary === self::ORBIT_BINARY) {
            return self::ORBIT_BINARY;
        }

        return escapeshellarg($binary);
    }

    private function ensureCommandNameIsValid(string $commandName): void
    {
        $this->ensureNoNullByte($commandName, 'command name');

        if (preg_match(self::COMMAND_NAME_PATTERN, $commandName) === 1) {
            return;
        }

        throw LocalExecutorCommandBuilderException::invalidCommandName();
    }

    private function ensureCommandIsAllowedForTarget(string $commandName, Node $targetNode): void
    {
        $allowedRoles = self::ALLOWED_COMMAND_ROLES[$commandName] ?? null;

        if ($allowedRoles === null) {
            throw LocalExecutorCommandBuilderException::commandNotAllowed($commandName);
        }

        foreach ($allowedRoles as $role) {
            if ($targetNode->hasActiveRole($role)) {
                return;
            }
        }

        throw LocalExecutorCommandBuilderException::commandNotAllowed($commandName);
    }

    private function ensureOperationTokenIsValid(string $operationToken): void
    {
        $this->ensureNoNullByte($operationToken, 'operation token');

        if (trim($operationToken) !== '') {
            return;
        }

        throw LocalExecutorCommandBuilderException::invalidOperationToken();
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return list<string>
     */
    private function argumentSegments(array $arguments): array
    {
        $segments = [];

        foreach ($arguments as $argument) {
            if (! is_scalar($argument)) {
                throw LocalExecutorCommandBuilderException::invalidArgument();
            }

            $value = $this->scalarToString($argument);
            $this->ensureNoNullByte($value, 'argument');

            $segments[] = escapeshellarg($value);
        }

        return $segments;
    }

    /**
     * @param  array<int|string, mixed>  $options
     * @return list<string>
     */
    private function optionSegments(array $options): array
    {
        $segments = [];

        foreach ($options as $key => $value) {
            $optionKey = $this->validatedOptionKey($key);

            if (! is_scalar($value)) {
                throw LocalExecutorCommandBuilderException::invalidOptionValue($optionKey);
            }

            $optionValue = $this->scalarToString($value);
            $this->ensureNoNullByte($optionValue, 'option value');

            $segments[] = "--{$optionKey}=".escapeshellarg($optionValue);
        }

        return $segments;
    }

    private function validatedOptionKey(int|string $key): string
    {
        if (! is_string($key)) {
            throw LocalExecutorCommandBuilderException::invalidOptionKey();
        }

        $this->ensureNoNullByte($key, 'option key');

        if (preg_match(self::OPTION_KEY_PATTERN, $key) === 1) {
            return $key;
        }

        throw LocalExecutorCommandBuilderException::invalidOptionKey();
    }

    private function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        throw LocalExecutorCommandBuilderException::invalidArgument();
    }

    private function ensureNoNullByte(string $value, string $field): void
    {
        if (! str_contains($value, "\0")) {
            return;
        }

        throw LocalExecutorCommandBuilderException::nullByte($field);
    }
}
