<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\NodeWireGuardSelfRouteProbe;
use App\Services\Nodes\NodeWireGuardServiceAddress;

final readonly class DatabaseConnectionProbe
{
    private const array SUPPORTED_DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    private const array NETWORK_REQUIRED_SUFFIXES = ['CONNECTION', 'HOST', 'PORT', 'DATABASE', 'USERNAME'];

    private const array SQLITE_REQUIRED_SUFFIXES = ['CONNECTION', 'DATABASE'];

    public function __construct(
        private EnvFileEditor $envFileEditor,
        private DatabaseConnectionEnvMapper $envMapper,
        private RemoteShell $remoteShell,
        private NodeWireGuardServiceAddress $serviceAddress,
        private NodeWireGuardSelfRouteProbe $wireGuardSelfRouteProbe,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function probe(Node $node): array
    {
        $issues = [];
        $scannedTargets = [];

        foreach ($this->targetsForNode($node) as $target) {
            $contents = $this->readEnvContents($node, $target);

            if ($contents === null) {
                $issues[] = $this->issue('database_connection.unverifiable', 'extra', $target, [
                    'reason' => 'env_unreadable',
                ]);

                continue;
            }

            $observed = $this->envFileEditor->parse($contents);
            $scannedTargets[] = $this->detailKey($this->targetDetail($target));
            $expected = $this->expectedEnvValues($target);
            $missing = array_keys(array_diff_key($expected, $observed));
            $mismatched = [];

            foreach (array_intersect_key($expected, $observed) as $key => $value) {
                if ((string) $observed[$key] !== (string) $value) {
                    $mismatched[$key] = $this->isSecretKey($key)
                        ? 'masked'
                        : [
                            'expected' => (string) $value,
                            'observed' => (string) $observed[$key],
                        ];
                }
            }

            if ($missing !== []) {
                $issues[] = $this->issue('database_connection.env_missing', 'missing', $target, [
                    'missing_keys' => $missing,
                ]);
            }

            if ($mismatched !== []) {
                $issues[] = $this->issue('database_connection.env_mismatch', 'mismatch', $target, [
                    'mismatched_keys' => $mismatched,
                ]);
            }

            $selfRouteIssue = $this->wireGuardSelfRouteIssue($target, $expected);

            if ($selfRouteIssue !== null) {
                $issues[] = $selfRouteIssue;
            }
        }

        foreach ($this->appsForNode($node) as $app) {
            $issues = [...$issues, ...$this->extraIssuesForObservedPrefixes($node, $app, $scannedTargets)];
        }

        foreach ($this->workspacesForNode($node) as $workspace) {
            $issues = [...$issues, ...$this->extraIssuesForObservedPrefixes($node, $workspace, $scannedTargets)];
        }

        return $issues;
    }

    private function readEnvContents(Node $node, DatabaseConnectionTarget $target): ?string
    {
        $path = $this->envPath($target);

        if ($path === null) {
            return null;
        }

        if ($this->shouldUseLocalFilesystem($node) && is_file($path)) {
            $contents = file_get_contents($path);

            return is_string($contents) ? $contents : null;
        }

        $script = sprintf(
            'test -f %1$s && cat %1$s',
            escapeshellarg($path),
        );
        $result = $this->remoteShell->run($node, $script, ['throw' => false]);

        return $result->successful() ? $result->stdout : null;
    }

    /**
     * @return array<string, string>
     */
    private function expectedEnvValues(DatabaseConnectionTarget $target): array
    {
        $connection = $target->connection;
        $host = $connection->host;

        if ($connection->driver !== 'sqlite' && $connection->node instanceof Node) {
            $host = $this->serviceAddress->forServiceOn($connection->node, $this->targetNode($target), $connection->driver);
        }

        return $this->envMapper->toEnvValues(
            $target->env_prefix,
            DatabaseConnectionPayload::fromArray([
                'driver' => $connection->driver,
                'host' => $host,
                'port' => $connection->port,
                'database' => $connection->database,
                'path' => $connection->path,
                'username' => $connection->username,
                'credentials' => $connection->credentials,
            ]),
        );
    }

    /**
     * @param  array<string, string>  $expected
     * @return array<string, mixed>|null
     */
    private function wireGuardSelfRouteIssue(DatabaseConnectionTarget $target, array $expected): ?array
    {
        $connection = $target->connection;

        if ($connection->driver === 'sqlite' || ! $connection->node instanceof Node) {
            return null;
        }

        $targetNode = $this->targetNode($target);

        if (! $connection->node->is($targetNode)) {
            return null;
        }

        $wireGuardAddress = trim((string) $targetNode->wireguard_address);
        $host = $expected["{$target->env_prefix}_HOST"] ?? null;

        if ($wireGuardAddress === '' || $host !== $wireGuardAddress) {
            return null;
        }

        $diagnostic = $this->wireGuardSelfRouteProbe->probe($targetNode);

        if (($diagnostic['ok'] ?? false) === true) {
            return null;
        }

        return $this->issue('database_connection.wireguard_self_route_unavailable', 'unverifiable', $target, [
            'connection' => $connection->slug,
            'node' => $targetNode->name,
            'host' => $host,
            ...$this->wireGuardSelfRouteDetail($diagnostic),
        ]);
    }

    /**
     * @return list<DatabaseConnectionTarget>
     */
    private function targetsForNode(Node $node): array
    {
        return DatabaseConnectionTarget::query()
            ->with(['connection.node', 'app.node', 'workspace.app.node'])
            ->where(function ($query) use ($node): void {
                $query
                    ->whereHas('app', fn ($appQuery) => $appQuery->where('node_id', $node->id))
                    ->orWhereHas('workspace.app', fn ($workspaceQuery) => $workspaceQuery->where('node_id', $node->id));
            })
            ->get()
            ->all();
    }

    private function targetNode(DatabaseConnectionTarget $target): Node
    {
        if ($target->app instanceof App && $target->app->node instanceof Node) {
            return $target->app->node;
        }

        if ($target->workspace instanceof Workspace && $target->workspace->app instanceof App && $target->workspace->app->node instanceof Node) {
            return $target->workspace->app->node;
        }

        throw new \RuntimeException('Database connection target has no owning node.');
    }

    /**
     * @param  array<string, mixed>  $diagnostic
     * @return array<string, mixed>
     */
    private function wireGuardSelfRouteDetail(array $diagnostic): array
    {
        return array_filter([
            'wireguard_address' => $diagnostic['wireguard_address'] ?? null,
            'platform' => $diagnostic['platform'] ?? null,
            'reason' => $diagnostic['reason'] ?? null,
            'message' => $diagnostic['message'] ?? null,
            'command' => $diagnostic['command'] ?? null,
            'exit_code' => $diagnostic['exit_code'] ?? null,
            'output' => $diagnostic['output'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function issue(string $key, string $kind, DatabaseConnectionTarget $target, array $detail = []): array
    {
        return [
            'family' => 'database_connection',
            'key' => $key,
            'kind' => $kind,
            'summary' => $key,
            'detail' => [
                ...$this->targetDetail($target),
                ...$detail,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function targetDetail(DatabaseConnectionTarget $target): array
    {
        if ($target->app instanceof App) {
            return [
                'target_type' => 'app',
                'target_id' => $target->app->id,
                'app' => $target->app->name,
                'env_prefix' => $target->env_prefix,
            ];
        }

        $workspace = $target->workspace;

        return [
            'target_type' => 'workspace',
            'target_id' => $workspace?->id,
            'workspace' => $workspace?->name,
            'app' => $workspace?->app?->name,
            'env_prefix' => $target->env_prefix,
        ];
    }

    private function envPath(DatabaseConnectionTarget $target): ?string
    {
        if ($target->app instanceof App) {
            return rtrim($target->app->path, '/').'/.env';
        }

        if ($target->workspace instanceof Workspace) {
            return rtrim($target->workspace->path, '/').'/.env';
        }

        return null;
    }

    /**
     * @param  list<string>  $scannedTargets
     * @return list<array<string, mixed>>
     */
    private function extraIssuesForObservedPrefixes(Node $node, App|Workspace $target, array $scannedTargets): array
    {
        $path = rtrim($target->path, '/').'/.env';
        $contents = $this->shouldUseLocalFilesystem($node) && is_file($path)
            ? file_get_contents($path)
            : $this->remoteShell->run($node, sprintf('test -f %1$s && cat %1$s', escapeshellarg($path)), ['throw' => false])->stdout;

        if (! is_string($contents) || $contents === '') {
            return [];
        }

        $values = $this->envFileEditor->parse($contents);
        $issues = [];

        foreach ($this->observedPrefixes($values) as $prefix) {
            $detail = $target instanceof App
                ? ['target_type' => 'app', 'target_id' => $target->id, 'app' => $target->name, 'env_prefix' => $prefix]
                : ['target_type' => 'workspace', 'target_id' => $target->id, 'workspace' => $target->name, 'app' => $target->app?->name, 'env_prefix' => $prefix];

            if (in_array($this->detailKey($detail), $scannedTargets, true)) {
                continue;
            }

            $payload = $this->payloadFromObservedValues($values, $prefix);

            if (! $payload instanceof DatabaseConnectionPayload) {
                $issues[] = [
                    'family' => 'database_connection',
                    'key' => 'database_connection.unverifiable',
                    'kind' => 'unverifiable',
                    'summary' => 'database_connection.unverifiable',
                    'detail' => [
                        ...$detail,
                        ...$payload,
                    ],
                ];

                continue;
            }

            $connection = $this->matchingConnection($payload, $node);

            if ($connection instanceof DatabaseConnection) {
                $issues[] = [
                    'family' => 'database_connection',
                    'key' => 'database_connection.target_missing',
                    'kind' => 'missing',
                    'summary' => 'database_connection.target_missing',
                    'detail' => [
                        ...$detail,
                        'database_connection_id' => $connection->id,
                        'connection' => $connection->slug,
                    ],
                ];

                continue;
            }

            $issues[] = [
                'family' => 'database_connection',
                'key' => 'database_connection.env_extra',
                'kind' => 'extra',
                'summary' => 'database_connection.env_extra',
                'detail' => $detail,
            ];
        }

        return $issues;
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
     * @return DatabaseConnectionPayload|array<string, mixed>
     */
    private function payloadFromObservedValues(array $values, string $prefix): DatabaseConnectionPayload|array
    {
        $driver = $values["{$prefix}_CONNECTION"] ?? null;

        if (! is_string($driver) || $driver === '') {
            return [
                'reason' => 'missing_connection_driver',
                'missing_keys' => ["{$prefix}_CONNECTION"],
            ];
        }

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            return ['reason' => 'unsupported_driver'];
        }

        $missing = $this->missingRequiredKeys($values, $prefix, $driver);

        if ($missing !== []) {
            return [
                'reason' => 'partial_env_group',
                'missing_keys' => $missing,
            ];
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
        $host = $connection->host;

        if ($connection->node instanceof Node) {
            try {
                $host = $this->serviceAddress->forServiceOn($connection->node, $node, $connection->driver);
            } catch (\RuntimeException) {
                return false;
            }
        }

        return $host === $payload->host
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
     * @param  array<string, mixed>  $detail
     */
    private function detailKey(array $detail): string
    {
        return implode(':', [
            (string) ($detail['target_type'] ?? ''),
            (string) ($detail['target_id'] ?? ''),
            (string) ($detail['env_prefix'] ?? ''),
        ]);
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

    private function isSecretKey(string $key): bool
    {
        return str_ends_with($key, '_PASSWORD')
            || str_contains($key, 'PASSWORD');
    }
}
