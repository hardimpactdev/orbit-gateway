<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\DatabaseConnection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final readonly class DatabaseQueryRunner
{
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 500;

    private const int MAX_FULL_LIMIT = 10000;

    private const int DEFAULT_TIMEOUT_SECONDS = 10;

    private const int DEFAULT_MAX_JSON_BYTES = 1048576;

    public function __construct(
        private DatabaseQueryClassifier $classifier,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function run(DatabaseConnection|DatabaseConnectionPayload|array $connection, string $sql, array $options = []): array
    {
        $payload = $this->payload($connection);
        $write = (bool) ($options['write'] ?? false);
        $full = (bool) ($options['full'] ?? false);
        $limit = $this->limit($options['limit'] ?? null, $full);
        $timeout = $this->positiveInteger($options['timeout'] ?? null, self::DEFAULT_TIMEOUT_SECONDS);
        $maxJsonBytes = $this->positiveInteger($options['max_json_bytes'] ?? null, self::DEFAULT_MAX_JSON_BYTES);
        $classification = $this->classifier->classify($sql);

        if ($classification->requiresWriteMode && ! $write) {
            throw new DatabaseQueryRunnerFailure(
                errorCode: 'database_query.write_not_allowed',
                message: 'This SQL statement requires explicit write mode.',
                meta: ['mode' => $classification->mode],
            );
        }

        $name = 'orbit_dynamic_database_'.str_replace('.', '_', uniqid('', true));
        Config::set("database.connections.{$name}", $this->configuration($payload, $timeout));

        $startedAt = microtime(true);

        try {
            $database = DB::connection($name);
            $database->getPdo();

            if ($classification->mode === 'write') {
                $affectedRows = $database->affectingStatement($sql);

                return [
                    'data' => ['affected_rows' => $affectedRows],
                    'meta' => [
                        'mode' => 'write',
                        'duration_ms' => $this->durationMs($startedAt),
                    ],
                ];
            }

            $statement = $database->getPdo()->prepare($sql);
            $statement->execute();
            $columns = $this->columns($statement);
            $rows = [];
            $truncatedByLimit = false;

            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (count($rows) >= $limit) {
                    $truncatedByLimit = true;

                    break;
                }

                $rows[] = $row;
            }

            $statement->closeCursor();
            $encoded = $this->compactRowsForJsonSize($rows, $columns, $maxJsonBytes);

            return [
                'data' => [
                    'columns' => $encoded['columns'],
                    'rows' => $encoded['rows'],
                ],
                'meta' => [
                    'mode' => 'read',
                    'limit' => $limit,
                    'total_rows' => $truncatedByLimit ? null : count($rows),
                    'returned_rows' => count($encoded['rows']),
                    'truncated' => $truncatedByLimit || $encoded['truncated'],
                    'truncated_by' => array_values(array_filter([
                        $truncatedByLimit ? 'limit' : null,
                        $encoded['truncated'] ? 'json_size' : null,
                    ])),
                    'max_json_bytes' => $maxJsonBytes,
                    'duration_ms' => $this->durationMs($startedAt),
                ],
            ];
        } catch (DatabaseQueryRunnerFailure $failure) {
            throw $failure;
        } catch (Throwable $throwable) {
            throw new DatabaseQueryRunnerFailure(
                errorCode: 'database_query.execution_failed',
                message: 'Database query execution failed.',
                meta: ['mode' => $classification->mode],
                previous: $throwable,
            );
        } finally {
            DB::purge($name);
            Config::offsetUnset("database.connections.{$name}");
        }
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

    /**
     * @return array<string, mixed>
     */
    private function configuration(DatabaseConnectionPayload $payload, int $timeout): array
    {
        return match ($payload->driver) {
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => $payload->path ?? $payload->database,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => $timeout * 1000,
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => $payload->host,
                'port' => $payload->port,
                'database' => $payload->database,
                'username' => $payload->username,
                'password' => $payload->password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'options' => [PDO::ATTR_TIMEOUT => $timeout],
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => $payload->host,
                'port' => $payload->port,
                'database' => $payload->database,
                'username' => $payload->username,
                'password' => $payload->password,
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'options' => [PDO::ATTR_TIMEOUT => $timeout],
            ],
            default => throw new InvalidArgumentException("Unsupported database connection driver [{$payload->driver}]."),
        };
    }

    private function limit(mixed $limit, bool $full): int
    {
        $limit = $this->positiveInteger($limit, self::DEFAULT_LIMIT);
        $maximum = $full ? self::MAX_FULL_LIMIT : self::MAX_LIMIT;

        return min($limit, $maximum);
    }

    private function positiveInteger(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * @return array<int, string>
     */
    private function columns(\PDOStatement $statement): array
    {
        $columns = [];

        for ($index = 0; $index < $statement->columnCount(); $index++) {
            $metadata = $statement->getColumnMeta($index);
            $name = is_array($metadata) ? $metadata['name'] ?? null : null;

            if (is_string($name) && $name !== '') {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $columns
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, truncated: bool}
     */
    private function compactRowsForJsonSize(array $rows, array $columns, int $maxJsonBytes): array
    {
        $truncated = false;

        while ($rows !== [] && $this->jsonBytes(['columns' => $columns, 'rows' => $rows]) > $maxJsonBytes) {
            array_pop($rows);
            $truncated = true;
        }

        if ($rows === [] && $this->jsonBytes(['columns' => $columns, 'rows' => $rows]) > $maxJsonBytes) {
            throw new RuntimeException('Query result metadata exceeds the JSON size limit.');
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function jsonBytes(array $payload): int
    {
        try {
            return strlen(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}

final class DatabaseQueryRunnerFailure extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $meta = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
