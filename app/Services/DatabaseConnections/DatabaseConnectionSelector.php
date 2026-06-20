<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Workspace;

final readonly class DatabaseConnectionSelector
{
    public function __construct(
        private DatabaseConnectionTargetResolver $resolver,
    ) {}

    public function resolve(string $target, ?string $connectionSlug = null): DatabaseConnection|DatabaseConnectionRegistryFailure
    {
        $app = $this->resolver->resolveApp($target);

        if ($app instanceof App) {
            return $this->resolveForOwner($target, 'app', 'app_id', $app->id, $connectionSlug);
        }

        $workspace = $this->resolver->resolveWorkspace($target);

        if ($workspace instanceof Workspace) {
            return $this->resolveForOwner($target, 'workspace', 'workspace_id', $workspace->id, $connectionSlug);
        }

        if ($connectionSlug !== null) {
            return DatabaseConnectionRegistryFailure::validation(
                'connection',
                $connectionSlug,
                'The --connection option can only select a connection attached to an app or workspace target.',
                ['target' => $target],
            );
        }

        return $this->resolveConnection($target);
    }

    private function resolveConnection(string $slug): DatabaseConnection|DatabaseConnectionRegistryFailure
    {
        $connection = DatabaseConnection::query()
            ->with(['node', 'targets.app', 'targets.workspace'])
            ->where('slug', $slug)
            ->first();

        if (! $connection instanceof DatabaseConnection) {
            return DatabaseConnectionRegistryFailure::notFound($slug);
        }

        return $connection;
    }

    private function resolveForOwner(string $target, string $ownerType, string $ownerColumn, int $ownerId, ?string $connectionSlug): DatabaseConnection|DatabaseConnectionRegistryFailure
    {
        $targets = DatabaseConnectionTarget::query()
            ->with(['connection.node', 'connection.targets.app', 'connection.targets.workspace'])
            ->where($ownerColumn, $ownerId)
            ->get();

        if ($connectionSlug !== null) {
            $selected = $targets->first(fn (DatabaseConnectionTarget $target): bool => $target->connection?->slug === $connectionSlug);

            if ($selected instanceof DatabaseConnectionTarget && $selected->connection instanceof DatabaseConnection) {
                return $selected->connection;
            }

            return DatabaseConnectionRegistryFailure::targetConnectionNotFound($ownerType, $ownerId, $connectionSlug);
        }

        if ($targets->isEmpty()) {
            return DatabaseConnectionRegistryFailure::notFound($target);
        }

        if ($targets->count() === 1) {
            return $targets->first()->connection;
        }

        $default = $targets->first(fn (DatabaseConnectionTarget $target): bool => $target->env_prefix === 'DB');

        if ($default instanceof DatabaseConnectionTarget) {
            return $default->connection;
        }

        return DatabaseConnectionRegistryFailure::ambiguousTarget(
            $target,
            $targets
                ->map(fn (DatabaseConnectionTarget $target): string => $target->connection instanceof DatabaseConnection ? $target->connection->slug : '')
                ->filter()
                ->values()
                ->all(),
        );
    }
}
