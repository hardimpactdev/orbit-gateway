<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\DatabaseConnection;
use App\Services\RemoteShell\RemoteLocalExecutor;
use JsonException;
use RuntimeException;

final readonly class DatabaseConnectionExecutor
{
    public function __construct(
        private DatabaseQueryRunner $runner,
        private DatabaseSchemaInspector $inspector,
        private RemoteLocalExecutor $localExecutor,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function query(DatabaseConnection $connection, string $sql, array $options = []): array
    {
        if ($connection->driver !== 'sqlite') {
            return $this->runner->run($connection, $sql, $options);
        }

        return $this->runSqliteLocal($connection, $sql, $options);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function tables(DatabaseConnection $connection): array
    {
        if ($connection->driver !== 'sqlite') {
            return $this->inspector->tables($connection);
        }

        return $this->runSqliteLocal($connection, "select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name", ['full' => true]);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function schema(DatabaseConnection $connection): array
    {
        if ($connection->driver !== 'sqlite') {
            return $this->inspector->schema($connection);
        }

        return $this->runSqliteLocal($connection, "select name, sql from sqlite_master where type in ('table', 'view') and name not like 'sqlite_%' order by name", ['full' => true]);
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function describe(DatabaseConnection $connection, string $table): array
    {
        if ($connection->driver !== 'sqlite') {
            return $this->inspector->describe($connection, $table);
        }

        $table = "'".str_replace("'", "''", $table)."'";

        return $this->runSqliteLocal($connection, "pragma table_info({$table})", ['full' => true]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function runSqliteLocal(DatabaseConnection $connection, string $sql, array $options): array
    {
        if ($connection->node === null) {
            throw new DatabaseQueryRunnerFailure(
                errorCode: 'database_connection.node_required',
                message: 'SQLite database connections require an owning node.',
            );
        }

        $result = $this->localExecutor->runInternal($connection->node, 'internal:database-query-local', [], [], [
            'input' => json_encode([
                'connection' => $this->executionPayload($connection),
                'sql' => $sql,
                'write' => (bool) ($options['write'] ?? false),
                'full' => (bool) ($options['full'] ?? false),
                'limit' => $options['limit'] ?? null,
                'timeout' => $options['timeout'] ?? null,
                'max_json_bytes' => $options['max_json_bytes'] ?? null,
            ], JSON_THROW_ON_ERROR),
            'throw' => false,
            'strict' => true,
        ]);

        if (! preg_match('/^\{.*\}\n?$/s', $result->stdout)) {
            throw new DatabaseQueryRunnerFailure(
                errorCode: 'database_query.invalid_remote_json',
                message: 'Remote database query returned mixed or invalid JSON output.',
            );
        }

        try {
            $payload = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DatabaseQueryRunnerFailure(
                errorCode: 'database_query.invalid_remote_json',
                message: 'Remote database query returned mixed or invalid JSON output.',
                previous: $exception,
            );
        }

        if (is_array($payload['success'] ?? null)) {
            $success = $payload['success'];

            return [
                'data' => is_array($success['data'] ?? null) ? $success['data'] : [],
                'meta' => is_array($success['meta'] ?? null) ? $success['meta'] : [],
            ];
        }

        if (is_array($payload['error'] ?? null)) {
            $error = $payload['error'];

            throw new DatabaseQueryRunnerFailure(
                errorCode: is_string($error['code'] ?? null) ? $error['code'] : 'database_query.remote_failed',
                message: is_string($error['message'] ?? null) ? $error['message'] : 'Remote database query failed.',
                meta: is_array($error['meta'] ?? null) ? $error['meta'] : [],
            );
        }

        throw new RuntimeException('Unexpected remote database query response.');
    }

    /**
     * @return array<string, mixed>
     */
    private function executionPayload(DatabaseConnection $connection): array
    {
        return [
            'driver' => $connection->driver,
            'host' => $connection->host,
            'port' => $connection->port,
            'database' => $connection->database,
            'path' => $connection->path,
            'username' => $connection->username,
        ];
    }
}
