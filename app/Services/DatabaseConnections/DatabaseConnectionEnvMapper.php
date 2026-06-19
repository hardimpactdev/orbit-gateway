<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

final class DatabaseConnectionEnvMapper
{
    /**
     * @return array<string, string>
     */
    public function toEnvValues(string $prefix, DatabaseConnectionPayload $payload): array
    {
        $values = [
            $this->key($prefix, 'CONNECTION') => $payload->driver,
        ];

        if ($payload->driver === 'sqlite') {
            $database = $payload->path ?? $payload->database;

            if ($database !== null) {
                $values[$this->key($prefix, 'DATABASE')] = $database;
            }

            return $values;
        }

        foreach ([
            'HOST' => $payload->host,
            'PORT' => $payload->port,
            'DATABASE' => $payload->database,
            'USERNAME' => $payload->username,
            'PASSWORD' => $payload->password,
        ] as $suffix => $value) {
            if ($value === null) {
                continue;
            }

            $values[$this->key($prefix, $suffix)] = (string) $value;
        }

        return $values;
    }

    private function key(string $prefix, string $suffix): string
    {
        return sprintf('%s_%s', $prefix, $suffix);
    }
}
