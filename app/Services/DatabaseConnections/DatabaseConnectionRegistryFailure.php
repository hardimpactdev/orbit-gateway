<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

final readonly class DatabaseConnectionRegistryFailure
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public string $code,
        public string $message,
        public array $meta,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function validation(string $field, mixed $value, string $message, array $meta = []): self
    {
        return new self(
            code: 'validation_failed',
            message: $message,
            meta: [
                'field' => $field,
                'value' => self::sanitizeValue($value),
                ...$meta,
            ],
        );
    }

    public static function notFound(string $slug): self
    {
        return new self(
            code: 'database_connection.not_found',
            message: "Database connection '{$slug}' was not found.",
            meta: ['slug' => $slug],
        );
    }

    public static function slugTaken(string $slug): self
    {
        return new self(
            code: 'database_connection.slug_taken',
            message: "Database connection slug '{$slug}' is already in use.",
            meta: [
                'field' => 'slug',
                'value' => $slug,
                'slug' => $slug,
            ],
        );
    }

    public static function targetConflict(string $ownerType, int $ownerId, string $envPrefix, string $slug): self
    {
        return new self(
            code: 'database_connection.target_conflict',
            message: "A different database connection is already attached to {$ownerType} {$ownerId} for env prefix '{$envPrefix}'.",
            meta: [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'env_prefix' => $envPrefix,
                'slug' => $slug,
            ],
        );
    }

    public static function targetNotFound(string $ownerType, int $ownerId, string $envPrefix, string $slug): self
    {
        return new self(
            code: 'database_connection.target_not_found',
            message: "Database connection '{$slug}' is not attached to {$ownerType} {$ownerId} for env prefix '{$envPrefix}'.",
            meta: [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'env_prefix' => $envPrefix,
                'slug' => $slug,
            ],
        );
    }

    public static function targetConnectionNotFound(string $ownerType, int $ownerId, string $slug): self
    {
        return new self(
            code: 'database_connection.target_not_found',
            message: "Database connection '{$slug}' is not attached to {$ownerType} {$ownerId}.",
            meta: [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'slug' => $slug,
            ],
        );
    }

    public static function hasTargets(string $slug, int $targetCount): self
    {
        return new self(
            code: 'database_connection.has_targets',
            message: "Database connection '{$slug}' cannot be removed while target mappings exist.",
            meta: [
                'slug' => $slug,
                'target_count' => $targetCount,
            ],
        );
    }

    /**
     * @param  array<int, string>  $connections
     */
    public static function ambiguousTarget(string $target, array $connections): self
    {
        sort($connections);

        return new self(
            code: 'database_connection.ambiguous_target',
            message: "Target '{$target}' has multiple database connections. Use --connection=<slug>.",
            meta: [
                'target' => $target,
                'connections' => $connections,
            ],
        );
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];

        foreach ($value as $key => $item) {
            if (in_array((string) $key, ['password', 'credentials'], true)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            $sanitized[$key] = self::sanitizeValue($item);
        }

        return $sanitized;
    }
}
