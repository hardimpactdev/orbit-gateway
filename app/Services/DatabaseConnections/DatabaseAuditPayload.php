<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;

final readonly class DatabaseAuditPayload
{
    public function __construct(private DatabaseQueryClassifier $classifier) {}

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function registry(string $operation, ?DatabaseConnection $connection = null, array $extra = []): array
    {
        return $this->compact([
            'operation' => $operation,
            ...$connection instanceof DatabaseConnection ? $this->connection($connection) : [],
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function query(DatabaseConnection $connection, string $target, string $sql, array $options = [], array $meta = [], array $extra = []): array
    {
        return $this->compact([
            ...$this->connection($connection),
            ...$this->target($connection, $target),
            ...$this->queryAttempt($target, $sql, $options, $meta),
            ...$this->resultMeta($meta),
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function queryAttempt(string $target, string $sql, array $options = [], array $meta = [], array $extra = []): array
    {
        $statementClass = $this->statementClass($sql);

        return $this->compact([
            'operation' => 'query',
            'target' => $target,
            'statement_hash' => hash('sha256', $sql),
            'statement_type' => $this->statementType($sql),
            'statement_class' => $statementClass,
            'mode' => $meta['mode'] ?? $statementClass,
            'write_requested' => (bool) ($options['write'] ?? false),
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function schema(string $operation, DatabaseConnection $connection, string $target, array $meta = [], ?string $table = null, array $extra = []): array
    {
        return $this->compact([
            'operation' => $operation,
            ...$this->connection($connection),
            ...$this->target($connection, $target),
            'table' => $table,
            'mode' => $meta['mode'] ?? 'read',
            ...$this->resultMeta($meta),
            ...$extra,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function connection(DatabaseConnection $connection): array
    {
        return $this->compact([
            'connection' => $connection->slug,
            'driver' => $connection->driver,
            'owning_node' => $connection->node?->name,
            'owning_node_wg_ip' => $connection->node?->wireguard_address,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function target(DatabaseConnection $connection, string $target): array
    {
        $targetRow = $connection->targets()
            ->with(['app', 'workspace'])
            ->get()
            ->first(fn ($row): bool => $row->app?->name === $target || $row->workspace?->name === $target);

        $targetType = null;
        $targetName = null;

        if ($targetRow instanceof DatabaseConnectionTarget) {
            if ($targetRow->app !== null) {
                $targetType = 'app';
                $targetName = $targetRow->app->name;
            } elseif ($targetRow->workspace !== null) {
                $targetType = 'workspace';
                $targetName = $targetRow->workspace->name;
            }
        }

        return $this->compact([
            'target' => $target,
            'target_type' => $targetType,
            'target_name' => $targetName,
            'env_prefix' => $targetRow?->env_prefix,
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function resultMeta(array $meta): array
    {
        return $this->compact([
            'limit' => $meta['limit'] ?? null,
            'total_rows' => $meta['total_rows'] ?? null,
            'returned_rows' => $meta['returned_rows'] ?? null,
            'affected_rows' => $meta['affected_rows'] ?? null,
            'truncated' => $meta['truncated'] ?? null,
            'truncated_by' => $meta['truncated_by'] ?? null,
            'max_json_bytes' => $meta['max_json_bytes'] ?? null,
            'duration_ms' => $meta['duration_ms'] ?? null,
        ]);
    }

    private function statementType(string $sql): string
    {
        $remaining = $this->stripLeadingComments($sql);
        $token = strtolower(strtok($remaining, " \t\r\n(") ?: 'unknown');

        return $token !== '' ? $token : 'unknown';
    }

    private function statementClass(string $sql): string
    {
        try {
            return $this->classifier->classify($sql)->mode;
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function stripLeadingComments(string $sql): string
    {
        $offset = 0;
        $length = strlen($sql);

        while ($offset < $length) {
            while ($offset < $length && ctype_space($sql[$offset])) {
                $offset++;
            }

            if (substr($sql, $offset, 2) === '--') {
                $lineEnd = strpos($sql, "\n", $offset + 2);
                $offset = $lineEnd === false ? $length : $lineEnd + 1;

                continue;
            }

            if (substr($sql, $offset, 2) === '/*') {
                $commentEnd = strpos($sql, '*/', $offset + 2);
                $offset = $commentEnd === false ? $length : $commentEnd + 2;

                continue;
            }

            break;
        }

        return substr($sql, $offset);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compact(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }
}
