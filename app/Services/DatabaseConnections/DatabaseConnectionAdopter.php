<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Contracts\RemoteShell;
use App\Data\Doctor\AdoptResult;
use App\Enums\AdoptAction;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Support\Str;

final readonly class DatabaseConnectionAdopter
{
    private const array SUPPORTED_DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    private const array NETWORK_REQUIRED_SUFFIXES = ['CONNECTION', 'HOST', 'PORT', 'DATABASE', 'USERNAME'];

    private const array SQLITE_REQUIRED_SUFFIXES = ['CONNECTION', 'DATABASE'];

    public function __construct(
        private EnvFileEditor $envFileEditor,
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return list<AdoptResult>
     */
    public function adopt(Node $node): array
    {
        $results = [];

        foreach ($this->workspacesForNode($node) as $workspace) {
            foreach ($this->payloadsFromEnvPath($node, rtrim($workspace->path, '/').'/.env') as $prefix => $payload) {
                $target = DatabaseConnectionTarget::query()
                    ->with('connection')
                    ->where('workspace_id', $workspace->id)
                    ->where('env_prefix', $prefix)
                    ->first();
                $baseSlug = sprintf(
                    '%s-%s%s',
                    Str::slug($workspace->name),
                    Str::slug($workspace->app->name),
                    $prefix === 'DB' ? '' : '-'.Str::slug($prefix)
                );

                [$connection, $action, $key] = $this->persistObservedConnection($target, $baseSlug, $payload, $node);

                DatabaseConnectionTarget::query()->updateOrCreate(
                    ['workspace_id' => $workspace->id, 'env_prefix' => $prefix],
                    ['database_connection_id' => $connection->id, 'app_id' => null],
                );

                $results[] = new AdoptResult(
                    family: 'database_connection',
                    key: $key,
                    action: $action,
                    summary: "Adopted database connection for workspace '{$workspace->name}'.",
                    detail: ['target_type' => 'workspace', 'target_id' => $workspace->id, 'workspace' => $workspace->name, 'app' => $workspace->app->name, 'env_prefix' => $prefix],
                );
            }
        }

        foreach ($this->appsForNode($node) as $app) {
            foreach ($this->payloadsFromEnvPath($node, rtrim($app->path, '/').'/.env') as $prefix => $payload) {
                $target = DatabaseConnectionTarget::query()
                    ->with('connection')
                    ->where('app_id', $app->id)
                    ->where('env_prefix', $prefix)
                    ->first();
                $baseSlug = sprintf(
                    '%s%s',
                    Str::slug($app->name),
                    $prefix === 'DB' ? '' : '-'.Str::slug($prefix)
                );

                [$connection, $action, $key] = $this->persistObservedConnection($target, $baseSlug, $payload, $node);

                DatabaseConnectionTarget::query()->updateOrCreate(
                    ['app_id' => $app->id, 'env_prefix' => $prefix],
                    ['database_connection_id' => $connection->id, 'workspace_id' => null],
                );

                $results[] = new AdoptResult(
                    family: 'database_connection',
                    key: $key,
                    action: $action,
                    summary: "Adopted database connection for app '{$app->name}'.",
                    detail: ['target_type' => 'app', 'target_id' => $app->id, 'app' => $app->name, 'env_prefix' => $prefix],
                );
            }
        }

        return $results;
    }

    /**
     * @return array<string, DatabaseConnectionPayload>
     */
    private function payloadsFromEnvPath(Node $node, string $path): array
    {
        $contents = $this->shouldUseLocalFilesystem($node) && is_file($path)
            ? file_get_contents($path)
            : $this->remoteShell->run($node, sprintf('test -f %1$s && cat %1$s', escapeshellarg($path)), ['throw' => false])->stdout;

        if (! is_string($contents) || $contents === '') {
            return [];
        }

        $values = $this->envFileEditor->parse($contents);
        $payloads = [];

        foreach ($this->observedPrefixes($values) as $prefix) {
            $payload = $this->payloadFromObservedValues($values, $prefix);

            if (! $payload instanceof DatabaseConnectionPayload) {
                continue;
            }

            if (! $this->payloadHasMeaningfulValues($payload)) {
                continue;
            }

            $payloads[$prefix] = $payload;
        }

        return $payloads;
    }

    private function upsertConnection(string $slug, DatabaseConnectionPayload $payload, Node $node): DatabaseConnection
    {
        return DatabaseConnection::query()->updateOrCreate(
            ['slug' => $slug],
            $this->attributesFromPayload($payload, node: $node),
        );
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $suffix = 2;

        while (DatabaseConnection::query()->where('slug', $slug)->exists()) {
            $slug = sprintf('%s-%d', $base, $suffix);
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return array{0: DatabaseConnection, 1: AdoptAction, 2: string}
     */
    private function persistObservedConnection(?DatabaseConnectionTarget $target, string $baseSlug, DatabaseConnectionPayload $payload, Node $node): array
    {
        if ($target?->connection instanceof DatabaseConnection) {
            $target->connection->fill($this->attributesFromPayload($payload, $target->connection, $node))->save();

            return [$target->connection->fresh(), AdoptAction::Updated, 'database_connection.env_mismatch'];
        }

        if (! $this->payloadHasMeaningfulValues($payload)) {
            throw new \RuntimeException('Unreachable empty payload.');
        }

        $connection = $this->matchingConnection($payload, $node) ?? $this->upsertConnection(
            slug: $this->uniqueSlug($baseSlug),
            payload: $payload,
            node: $node,
        );

        return [$connection, AdoptAction::Created, 'database_connection.target_extra'];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFromPayload(DatabaseConnectionPayload $payload, ?DatabaseConnection $existing = null, ?Node $node = null): array
    {
        $credentials = $this->mergeCredentials($existing, $payload);

        if ($payload->driver === 'sqlite') {
            return [
                'node_id' => $node instanceof Node ? $node->id : $existing?->node_id,
                'driver' => $payload->driver,
                'host' => null,
                'port' => null,
                'database' => null,
                'path' => $payload->path ?? $existing?->path,
                'username' => null,
                'credentials' => $credentials,
            ];
        }

        return [
            'driver' => $payload->driver,
            'host' => $payload->host ?? $existing?->host,
            'port' => $payload->port ?? $existing?->port,
            'database' => $payload->database ?? $existing?->database,
            'path' => null,
            'username' => $payload->username ?? $existing?->username,
            'credentials' => $credentials,
        ];
    }

    private function payloadHasMeaningfulValues(DatabaseConnectionPayload $payload): bool
    {
        if ($payload->driver === 'sqlite') {
            return ($payload->path ?? $payload->database) !== null;
        }

        return $payload->host !== null
            || $payload->port !== null
            || $payload->database !== null
            || $payload->username !== null
            || $payload->password !== null;
    }

    /**
     * @return array{password?: string}
     */
    private function mergeCredentials(?DatabaseConnection $connection, DatabaseConnectionPayload $payload): array
    {
        $credentials = is_array($connection?->credentials) ? $connection->credentials : [];

        if ($payload->password !== null) {
            $credentials['password'] = $payload->password;
        }

        return $credentials;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function observedPrefixes(array $values): array
    {
        $prefixes = [];

        foreach ($values as $key => $value) {
            $ending = '_CONNECTION';

            if (! str_ends_with($key, $ending)) {
                continue;
            }

            $prefix = substr($key, 0, -strlen($ending));

            if ($this->validEnvPrefix($prefix) && in_array($value, self::SUPPORTED_DRIVERS, true)) {
                $prefixes[] = $prefix;
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function payloadFromObservedValues(array $values, string $prefix): ?DatabaseConnectionPayload
    {
        $driver = $values["{$prefix}_CONNECTION"] ?? null;

        if (! is_string($driver) || $driver === '' || ! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            return null;
        }

        if ($this->missingRequiredKeys($values, $prefix, $driver) !== []) {
            return null;
        }

        return DatabaseConnectionPayload::fromArray([
            'driver' => $driver,
            'host' => $driver === 'sqlite' ? null : ($values["{$prefix}_HOST"] ?? null),
            'port' => $driver === 'sqlite' ? null : ($values["{$prefix}_PORT"] ?? null),
            'database' => $driver === 'sqlite' ? null : ($values["{$prefix}_DATABASE"] ?? null),
            'path' => $driver === 'sqlite' ? ($values["{$prefix}_DATABASE"] ?? null) : null,
            'username' => $driver === 'sqlite' ? null : ($values["{$prefix}_USERNAME"] ?? null),
            'password' => $values["{$prefix}_PASSWORD"] ?? null,
        ]);
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    private function missingRequiredKeys(array $values, string $prefix, string $driver): array
    {
        $requiredSuffixes = $driver === 'sqlite'
            ? self::SQLITE_REQUIRED_SUFFIXES
            : self::NETWORK_REQUIRED_SUFFIXES;

        $missing = [];

        foreach ($requiredSuffixes as $suffix) {
            $key = "{$prefix}_{$suffix}";

            if (! is_string($values[$key] ?? null) || $values[$key] === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function matchingConnection(DatabaseConnectionPayload $payload, Node $node): ?DatabaseConnection
    {
        $connections = DatabaseConnection::query()
            ->where('driver', $payload->driver)
            ->get();

        foreach ($connections as $connection) {
            if ($this->connectionMatchesPayload($connection, $payload, $node)) {
                return $connection;
            }
        }

        return null;
    }

    private function connectionMatchesPayload(DatabaseConnection $connection, DatabaseConnectionPayload $payload, Node $node): bool
    {
        if ($payload->driver === 'sqlite') {
            return $connection->node_id === $node->id && $connection->path === $payload->path;
        }

        $password = $connection->credentials['password'] ?? null;

        return $connection->host === $payload->host
            && $connection->port === $payload->port
            && $connection->database === $payload->database
            && $connection->username === $payload->username
            && (! is_string($payload->password) || $password === $payload->password);
    }

    private function validEnvPrefix(string $value): bool
    {
        return preg_match('/^[A-Z][A-Z0-9_]*$/', $value) === 1;
    }

    /**
     * @return list<App>
     */
    private function appsForNode(Node $node): array
    {
        return App::query()->where('node_id', $node->id)->get()->all();
    }

    /**
     * @return list<Workspace>
     */
    private function workspacesForNode(Node $node): array
    {
        return Workspace::query()->with('app')->whereHas('app', fn ($query) => $query->where('node_id', $node->id))->get()->all();
    }

    private function shouldUseLocalFilesystem(Node $node): bool
    {
        return $node->hasActiveRole('gateway');
    }
}
