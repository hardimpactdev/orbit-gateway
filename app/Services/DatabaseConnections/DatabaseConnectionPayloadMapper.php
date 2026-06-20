<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\App;
use App\Models\AppInstanceDatabaseConnectionTarget;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Workspace;

final class DatabaseConnectionPayloadMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(DatabaseConnection $connection): array
    {
        $connection->loadMissing(['node', 'targets.app', 'targets.workspace', 'instanceTargets.instance.app']);

        $targets = collect([
            ...$connection->targets->map(fn (DatabaseConnectionTarget $target): array => [
                'type' => $target->app_id !== null ? 'app' : 'workspace',
                'name' => $this->targetName($target),
                'env_prefix' => $target->env_prefix,
            ])->all(),
            ...$connection->instanceTargets->map(fn (AppInstanceDatabaseConnectionTarget $target): array => [
                'type' => 'app_instance',
                'app' => $target->instance->app->name,
                'instance' => $target->instance->name,
                'env_prefix' => $target->env_prefix,
            ])->all(),
        ])
            ->sortBy(fn (array $target): string => $target['type'].':'.($target['name'] ?? $target['app'] ?? '').':'.($target['instance'] ?? '').':'.$target['env_prefix'])
            ->values()
            ->all();

        return [
            'slug' => $connection->slug,
            'driver' => $connection->driver,
            'host' => $connection->host,
            'port' => $connection->port,
            'database' => $connection->database,
            'path' => $connection->path,
            'username' => $connection->username,
            'node' => $connection->node?->name,
            'targets' => $targets,
        ];
    }

    private function targetName(DatabaseConnectionTarget $target): string
    {
        if ($target->app instanceof App) {
            return $target->app->name;
        }

        if ($target->workspace instanceof Workspace) {
            return $target->workspace->name;
        }

        return '';
    }
}
