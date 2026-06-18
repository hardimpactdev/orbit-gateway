<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Models\App;
use App\Models\AppInstance;
use App\Models\AppInstanceDatabaseConnectionTarget;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final class DatabaseConnectionRegistry
{
    private const string SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    private const int SLUG_MAX_LENGTH = 40;

    /**
     * @return Collection<int, DatabaseConnection>
     */
    public function list(?App $app = null, ?Workspace $workspace = null, ?Node $node = null): Collection
    {
        return DatabaseConnection::query()
            ->with(['node', 'targets.app', 'targets.workspace', 'instanceTargets.instance.app'])
            ->when(
                $app instanceof App,
                fn (Builder $query): Builder => $query->where(function (Builder $nested) use ($app): void {
                    $nested->whereHas(
                        'targets',
                        fn (Builder $targetQuery): Builder => $targetQuery->where('app_id', $app->id)
                    )
                        ->orWhereHas(
                            'instanceTargets.instance',
                            fn (Builder $targetQuery): Builder => $targetQuery->where('app_id', $app->id)
                        );
                }),
            )
            ->when(
                $workspace instanceof Workspace,
                fn (Builder $query): Builder => $query->whereHas(
                    'targets',
                    fn (Builder $targetQuery): Builder => $targetQuery->where('workspace_id', $workspace->id)
                ),
            )
            ->when(
                $node instanceof Node,
                fn (Builder $query): Builder => $query->where(function (Builder $nested) use ($node): void {
                    $nested->where('node_id', $node->id)
                        ->orWhereHas('targets.app', fn (Builder $appQuery): Builder => $appQuery->where('node_id', $node->id))
                        ->orWhereHas('targets.workspace.app', fn (Builder $workspaceQuery): Builder => $workspaceQuery->where('node_id', $node->id))
                        ->orWhereHas('instanceTargets.instance.app', fn (Builder $appQuery): Builder => $appQuery->where('node_id', $node->id));
                }),
            )
            ->orderBy('slug')
            ->get();
    }

    public function show(string $slug): DatabaseConnection|DatabaseConnectionRegistryFailure
    {
        return $this->findConnection($slug, withRelations: true);
    }

    public function create(string $slug, array $attributes): DatabaseConnection|DatabaseConnectionRegistryFailure
    {
        $slugValidation = $this->validateSlug($slug);

        if ($slugValidation instanceof DatabaseConnectionRegistryFailure) {
            return $slugValidation;
        }

        $slugUniqueness = $this->validateSlugUniqueness($slug);

        if ($slugUniqueness instanceof DatabaseConnectionRegistryFailure) {
            return $slugUniqueness;
        }

        $payload = $this->payloadFromAttributes($attributes);

        if ($payload instanceof DatabaseConnectionRegistryFailure) {
            return $payload;
        }

        $payloadValidation = $this->validatePayload($payload, $attributes);

        if ($payloadValidation instanceof DatabaseConnectionRegistryFailure) {
            return $payloadValidation;
        }

        return DatabaseConnection::query()->create([
            'node_id' => $this->normalizeNodeId($attributes['node_id'] ?? null),
            'slug' => $slug,
            ...$this->attributesFromPayload($payload),
        ]);
    }

    public function update(string $slug, array $attributes): DatabaseConnection|DatabaseConnectionRegistryFailure
    {
        $connection = $this->findConnection($slug);

        if ($connection instanceof DatabaseConnectionRegistryFailure) {
            return $connection;
        }

        $targetSlug = $attributes['slug'] ?? $slug;
        $slugValidation = $this->validateSlug($targetSlug);

        if ($slugValidation instanceof DatabaseConnectionRegistryFailure) {
            return $slugValidation;
        }

        $slugUniqueness = $this->validateSlugUniqueness($targetSlug, $connection->id);

        if ($slugUniqueness instanceof DatabaseConnectionRegistryFailure) {
            return $slugUniqueness;
        }

        $payloadAttributes = $this->mergeUpdateAttributes($connection, $attributes);
        $payload = $this->payloadFromAttributes($payloadAttributes);

        if ($payload instanceof DatabaseConnectionRegistryFailure) {
            return $payload;
        }

        $payloadValidation = $this->validatePayload($payload, $payloadAttributes);

        if ($payloadValidation instanceof DatabaseConnectionRegistryFailure) {
            return $payloadValidation;
        }

        $connection->fill([
            'node_id' => array_key_exists('node_id', $attributes) ? $this->normalizeNodeId($attributes['node_id']) : $connection->node_id,
            'slug' => $targetSlug,
            ...$this->attributesFromPayload($payload, $payloadAttributes),
        ]);
        $connection->save();

        return $connection->fresh();
    }

    public function remove(string $slug, bool $force = false): bool|DatabaseConnectionRegistryFailure
    {
        $connection = $this->findConnection($slug);

        if ($connection instanceof DatabaseConnectionRegistryFailure) {
            return $connection;
        }

        $targetCount = $connection->targets()->count() + $connection->instanceTargets()->count();

        if ($targetCount > 0 && ! $force) {
            return DatabaseConnectionRegistryFailure::hasTargets($slug, $targetCount);
        }

        DatabaseConnectionTarget::query()
            ->where('database_connection_id', $connection->id)
            ->delete();
        AppInstanceDatabaseConnectionTarget::query()
            ->where('database_connection_id', $connection->id)
            ->delete();
        $connection->delete();

        return true;
    }

    public function attachToApp(string $slug, App $app, string $envPrefix): DatabaseConnectionTarget|DatabaseConnectionRegistryFailure
    {
        return $this->attach($slug, 'app', $app->id, $envPrefix);
    }

    public function attachToWorkspace(string $slug, Workspace $workspace, string $envPrefix): DatabaseConnectionTarget|DatabaseConnectionRegistryFailure
    {
        return $this->attach($slug, 'workspace', $workspace->id, $envPrefix);
    }

    public function attachToAppInstance(string $slug, AppInstance $instance, string $envPrefix): AppInstanceDatabaseConnectionTarget|DatabaseConnectionRegistryFailure
    {
        return $this->attachInstance($slug, $instance, $envPrefix);
    }

    public function detachFromApp(string $slug, App $app, string $envPrefix): DatabaseConnectionTarget|DatabaseConnectionRegistryFailure
    {
        return $this->detach($slug, 'app', $app->id, $envPrefix);
    }

    public function detachFromWorkspace(string $slug, Workspace $workspace, string $envPrefix): DatabaseConnectionTarget|DatabaseConnectionRegistryFailure
    {
        return $this->detach($slug, 'workspace', $workspace->id, $envPrefix);
    }

    public function detachFromAppInstance(string $slug, AppInstance $instance, string $envPrefix): AppInstanceDatabaseConnectionTarget|DatabaseConnectionRegistryFailure
    {
        return $this->detachInstance($slug, $instance, $envPrefix);
    }

    private function findConnection(string $slug, bool $withRelations = false): DatabaseConnection|DatabaseConnectionRegistryFailure
    {
        $query = DatabaseConnection::query();

        if ($withRelations) {
            $query->with(['node', 'targets.app', 'targets.workspace', 'instanceTargets.instance.app']);
        }

        $connection = $query->where('slug', $slug)->first();

        if (! $connection instanceof DatabaseConnection) {
            return DatabaseConnectionRegistryFailure::notFound($slug);
        }

        return $connection;
    }

    private function validateSlug(string $slug): ?DatabaseConnectionRegistryFailure
    {
        if ($slug === '') {
            return DatabaseConnectionRegistryFailure::validation('slug', $slug, 'Database connection slug is required.');
        }

        if (mb_strlen($slug) > self::SLUG_MAX_LENGTH) {
            return DatabaseConnectionRegistryFailure::validation('slug', $slug, 'Database connection slug must be at most 40 characters.');
        }

        if (! preg_match(self::SLUG_PATTERN, $slug)) {
            return DatabaseConnectionRegistryFailure::validation(
                'slug',
                $slug,
                'Database connection slug must use lowercase letters, digits, or hyphens, and start and end with an alphanumeric character.',
            );
        }

        return null;
    }

    private function payloadFromAttributes(array $attributes): DatabaseConnectionPayload|DatabaseConnectionRegistryFailure
    {
        try {
            return DatabaseConnectionPayload::fromArray($attributes);
        } catch (InvalidArgumentException $exception) {
            $field = $attributes['driver'] ?? null ? 'driver' : 'payload';

            return DatabaseConnectionRegistryFailure::validation($field, $attributes['driver'] ?? null, $exception->getMessage());
        }
    }

    private function validateSlugUniqueness(string $slug, ?int $ignoreId = null): ?DatabaseConnectionRegistryFailure
    {
        $exists = DatabaseConnection::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            return DatabaseConnectionRegistryFailure::slugTaken($slug);
        }

        return null;
    }

    private function validatePayload(DatabaseConnectionPayload $payload, array $attributes): ?DatabaseConnectionRegistryFailure
    {
        if ($payload->driver === 'sqlite') {
            if ($this->normalizeNodeId($attributes['node_id'] ?? null) === null) {
                return DatabaseConnectionRegistryFailure::validation('payload', $attributes, 'Sqlite database connections require node_id.');
            }

            if (! is_string($payload->path) || $payload->path === '') {
                return DatabaseConnectionRegistryFailure::validation('payload', $attributes, 'Sqlite database connections require path.');
            }

            return null;
        }

        foreach (['host', 'port', 'database', 'username'] as $field) {
            if ($payload->{$field} === null || $payload->{$field} === '') {
                return DatabaseConnectionRegistryFailure::validation('payload', $attributes, sprintf('%s database connections require %s.', strtoupper($payload->driver), $field));
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFromPayload(DatabaseConnectionPayload $payload, array $attributes = []): array
    {
        $credentials = $this->credentialsFromPayload($payload, $attributes);

        if ($payload->driver === 'sqlite') {
            return [
                'driver' => $payload->driver,
                'host' => null,
                'port' => null,
                'database' => null,
                'path' => $payload->path,
                'username' => null,
                'credentials' => $credentials,
            ];
        }

        return [
            'driver' => $payload->driver,
            'host' => $payload->host,
            'port' => $payload->port,
            'database' => $payload->database,
            'path' => null,
            'username' => $payload->username,
            'credentials' => $credentials,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeUpdateAttributes(DatabaseConnection $connection, array $attributes): array
    {
        return [
            'driver' => $attributes['driver'] ?? $connection->driver,
            'host' => array_key_exists('host', $attributes) ? $attributes['host'] : $connection->host,
            'port' => array_key_exists('port', $attributes) ? $attributes['port'] : $connection->port,
            'database' => array_key_exists('database', $attributes) ? $attributes['database'] : $connection->database,
            'path' => array_key_exists('path', $attributes) ? $attributes['path'] : $connection->path,
            'username' => array_key_exists('username', $attributes) ? $attributes['username'] : $connection->username,
            'password' => $this->passwordForUpdate($connection, $attributes),
            'node_id' => array_key_exists('node_id', $attributes) ? $attributes['node_id'] : $connection->node_id,
        ];
    }

    private function passwordForUpdate(DatabaseConnection $connection, array $attributes): ?string
    {
        if (($attributes['clear_password'] ?? false) === true) {
            return null;
        }

        if (array_key_exists('password', $attributes)) {
            return is_string($attributes['password']) ? $attributes['password'] : null;
        }

        $existingPassword = $connection->credentials['password'] ?? null;

        return is_string($existingPassword) ? $existingPassword : null;
    }

    /**
     * @return array{password?: string}
     */
    private function credentialsFromPayload(DatabaseConnectionPayload $payload, array $attributes): array
    {
        if (($attributes['clear_password'] ?? false) === true) {
            return [];
        }

        return $payload->credentials();
    }

    private function attach(string $slug, string $ownerType, int $ownerId, string $envPrefix): DatabaseConnectionTarget|DatabaseConnectionRegistryFailure
    {
        $connection = $this->findConnection($slug);

        if ($connection instanceof DatabaseConnectionRegistryFailure) {
            return $connection;
        }

        $target = $this->targetQuery($ownerType, $ownerId, $envPrefix)->first();

        if ($target instanceof DatabaseConnectionTarget) {
            if ($target->database_connection_id === $connection->id) {
                return $target;
            }

            return DatabaseConnectionRegistryFailure::targetConflict($ownerType, $ownerId, $envPrefix, $slug);
        }

        return DatabaseConnectionTarget::query()->create([
            'database_connection_id' => $connection->id,
            'app_id' => $ownerType === 'app' ? $ownerId : null,
            'workspace_id' => $ownerType === 'workspace' ? $ownerId : null,
            'env_prefix' => $envPrefix,
        ]);
    }

    private function detach(string $slug, string $ownerType, int $ownerId, string $envPrefix): DatabaseConnectionTarget|DatabaseConnectionRegistryFailure
    {
        $connection = $this->findConnection($slug);

        if ($connection instanceof DatabaseConnectionRegistryFailure) {
            return $connection;
        }

        $target = $this->targetQuery($ownerType, $ownerId, $envPrefix)
            ->where('database_connection_id', $connection->id)
            ->first();

        if (! $target instanceof DatabaseConnectionTarget) {
            return DatabaseConnectionRegistryFailure::targetNotFound($ownerType, $ownerId, $envPrefix, $slug);
        }

        $target->delete();

        return $target;
    }

    private function attachInstance(string $slug, AppInstance $instance, string $envPrefix): AppInstanceDatabaseConnectionTarget|DatabaseConnectionRegistryFailure
    {
        $connection = $this->findConnection($slug);

        if ($connection instanceof DatabaseConnectionRegistryFailure) {
            return $connection;
        }

        $target = AppInstanceDatabaseConnectionTarget::query()
            ->where('app_instance_id', $instance->id)
            ->where('env_prefix', $envPrefix)
            ->first();

        if ($target instanceof AppInstanceDatabaseConnectionTarget) {
            if ($target->database_connection_id === $connection->id) {
                return $target;
            }

            return DatabaseConnectionRegistryFailure::targetConflict('app_instance', $instance->id, $envPrefix, $slug);
        }

        return AppInstanceDatabaseConnectionTarget::query()->create([
            'database_connection_id' => $connection->id,
            'app_instance_id' => $instance->id,
            'env_prefix' => $envPrefix,
        ]);
    }

    private function detachInstance(string $slug, AppInstance $instance, string $envPrefix): AppInstanceDatabaseConnectionTarget|DatabaseConnectionRegistryFailure
    {
        $connection = $this->findConnection($slug);

        if ($connection instanceof DatabaseConnectionRegistryFailure) {
            return $connection;
        }

        $target = AppInstanceDatabaseConnectionTarget::query()
            ->where('app_instance_id', $instance->id)
            ->where('env_prefix', $envPrefix)
            ->where('database_connection_id', $connection->id)
            ->first();

        if (! $target instanceof AppInstanceDatabaseConnectionTarget) {
            return DatabaseConnectionRegistryFailure::targetNotFound('app_instance', $instance->id, $envPrefix, $slug);
        }

        $target->delete();

        return $target;
    }

    private function targetQuery(string $ownerType, int $ownerId, string $envPrefix)
    {
        return DatabaseConnectionTarget::query()
            ->where($ownerType.'_id', $ownerId)
            ->where('env_prefix', $envPrefix);
    }

    private function normalizeNodeId(mixed $nodeId): ?int
    {
        if (is_int($nodeId)) {
            return $nodeId;
        }

        if (is_string($nodeId) && is_numeric($nodeId)) {
            return (int) $nodeId;
        }

        return null;
    }
}
