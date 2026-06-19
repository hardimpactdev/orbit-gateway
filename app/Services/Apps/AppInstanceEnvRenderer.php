<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\AppInstance;
use App\Models\AppInstanceEnvVariable;
use App\Models\DatabaseConnection;

final readonly class AppInstanceEnvRenderer
{
    /**
     * @return list<array{key: string, value: string|null, secret: bool}>
     */
    public function variables(AppInstance $instance): array
    {
        $instance->loadMissing('envVariables');

        return $instance->envVariables
            ->map(fn (AppInstanceEnvVariable $variable): array => $this->variablePayload($variable))
            ->values()
            ->all();
    }

    public function set(AppInstance $instance, string $key, string $value): AppInstanceEnvVariable
    {
        return $instance->envVariables()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'secret' => false],
        );
    }

    /**
     * @return array<string, array{value: string|null, secret: bool, source: string}>
     */
    public function render(AppInstance $instance): array
    {
        $instance->loadMissing(['envVariables', 'databaseConnectionTargets.connection']);

        $env = [];

        foreach ($instance->envVariables as $variable) {
            $env[$variable->key] = [
                'value' => $variable->secret ? null : $variable->value,
                'secret' => (bool) $variable->secret,
                'source' => 'instance',
            ];
        }

        foreach ($instance->databaseConnectionTargets as $target) {
            $connection = $target->connection;

            if (! $connection instanceof DatabaseConnection) {
                continue;
            }

            foreach ($this->databaseVariables($connection, $target->env_prefix) as $key => $entry) {
                $env[$key] = $entry;
            }
        }

        ksort($env);

        return $env;
    }

    /**
     * @return array{key: string, value: string|null, secret: bool}
     */
    public function variablePayload(AppInstanceEnvVariable $variable): array
    {
        return [
            'key' => $variable->key,
            'value' => $variable->secret ? null : $variable->value,
            'secret' => (bool) $variable->secret,
        ];
    }

    /**
     * @return array<string, array{value: string|null, secret: bool, source: string}>
     */
    private function databaseVariables(DatabaseConnection $connection, string $prefix): array
    {
        $prefix = strtoupper($prefix);
        $password = $connection->credentials['password'] ?? null;

        $values = [
            "{$prefix}_CONNECTION" => $connection->driver,
            "{$prefix}_HOST" => $connection->host,
            "{$prefix}_PORT" => $connection->port === null ? null : (string) $connection->port,
            "{$prefix}_DATABASE" => $connection->database,
            "{$prefix}_USERNAME" => $connection->username,
        ];

        $payload = [];

        foreach ($values as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $payload[$key] = [
                'value' => $value,
                'secret' => false,
                'source' => 'database',
            ];
        }

        $payload["{$prefix}_PASSWORD"] = [
            'value' => null,
            'secret' => is_string($password) && $password !== '',
            'source' => 'database',
        ];

        return $payload;
    }
}
