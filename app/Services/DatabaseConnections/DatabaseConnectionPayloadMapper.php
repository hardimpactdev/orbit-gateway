<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\App;
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
        $connection->loadMissing(['node', 'targets.app', 'targets.workspace']);

        return [
            'slug' => $connection->slug,
            'driver' => $connection->driver,
            'host' => $connection->host,
            'port' => $connection->port,
            'database' => $connection->database,
            'path' => $connection->path,
            'username' => $connection->username,
            'node' => $connection->node?->name,
            'targets' => $connection->targets
                ->map(fn (DatabaseConnectionTarget $target): array => [
                    'type' => $target->app_id !== null ? 'app' : 'workspace',
                    'name' => $this->targetName($target),
                    'env_prefix' => $target->env_prefix,
                ])
                ->sortBy(fn (array $target): string => $target['type'].':'.$target['name'].':'.$target['env_prefix'])
                ->values()
                ->all(),
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
