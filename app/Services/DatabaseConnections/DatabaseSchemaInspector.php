<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\DatabaseConnection;

final readonly class DatabaseSchemaInspector
{
    public function __construct(
        private DatabaseQueryRunner $runner,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function tables(DatabaseConnection|DatabaseConnectionPayload|array $connection): array
    {
        $payload = $this->payload($connection);

        return $this->runner->run($payload, match ($payload->driver) {
            'sqlite' => "select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name",
            'mysql' => 'show tables',
            'pgsql' => "select table_name from information_schema.tables where table_schema = 'public' order by table_name",
            default => 'select 1',
        });
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function schema(DatabaseConnection|DatabaseConnectionPayload|array $connection): array
    {
        $payload = $this->payload($connection);

        return $this->runner->run($payload, match ($payload->driver) {
            'sqlite' => "select name, sql from sqlite_master where type in ('table', 'view') and name not like 'sqlite_%' order by name",
            'mysql' => 'select table_name, table_type from information_schema.tables where table_schema = database() order by table_name',
            'pgsql' => "select table_name, table_type from information_schema.tables where table_schema = 'public' order by table_name",
            default => 'select 1',
        }, ['full' => true]);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function describe(DatabaseConnection|DatabaseConnectionPayload|array $connection, string $table): array
    {
        $payload = $this->payload($connection);

        return $this->runner->run($payload, match ($payload->driver) {
            'sqlite' => sprintf('pragma table_info(%s)', $this->sqliteIdentifier($table)),
            'mysql' => sprintf('describe `%s`', str_replace('`', '``', $table)),
            'pgsql' => sprintf(
                "select column_name, data_type, is_nullable, column_default from information_schema.columns where table_schema = 'public' and table_name = '%s' order by ordinal_position",
                str_replace("'", "''", $table),
            ),
            default => 'select 1',
        }, ['full' => true]);
    }

    private function payload(DatabaseConnection|DatabaseConnectionPayload|array $connection): DatabaseConnectionPayload
    {
        if ($connection instanceof DatabaseConnectionPayload) {
            return $connection;
        }

        if ($connection instanceof DatabaseConnection) {
            return DatabaseConnectionPayload::fromArray([
                'driver' => $connection->driver,
                'host' => $connection->host,
                'port' => $connection->port,
                'database' => $connection->database,
                'path' => $connection->path,
                'username' => $connection->username,
                'credentials' => $connection->credentials ?? [],
            ]);
        }

        return DatabaseConnectionPayload::fromArray($connection);
    }

    private function sqliteIdentifier(string $identifier): string
    {
        return "'".str_replace("'", "''", $identifier)."'";
    }
}
