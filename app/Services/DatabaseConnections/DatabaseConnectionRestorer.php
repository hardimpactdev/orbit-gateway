<?php

declare(strict_types=1);

namespace App\Services\DatabaseConnections;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\NodeWireGuardServiceAddress;
use RuntimeException;

final readonly class DatabaseConnectionRestorer
{
    public function __construct(
        private EnvFileEditor $envFileEditor,
        private DatabaseConnectionEnvMapper $envMapper,
        private RemoteShell $remoteShell,
        private NodeWireGuardServiceAddress $serviceAddress,
    ) {}

    public function restore(DatabaseConnectionTarget $target): void
    {
        $path = $this->envPath($target);

        if ($path === null) {
            throw new RuntimeException('Database connection target has no readable path.');
        }

        $contents = $this->readContents($target, $path);
        $updated = $this->envFileEditor->update($contents, $this->expectedEnvValues($target));

        if ($this->shouldUseLocalFilesystem($target) && is_file($path)) {
            file_put_contents($path, $updated);

            return;
        }

        $script = sprintf(
            'mkdir -p %s && printf %%s %s | base64 -d > %s',
            escapeshellarg(dirname($path)),
            escapeshellarg(base64_encode($updated)),
            escapeshellarg($path),
        );
        $result = $this->remoteShell->run($this->targetNode($target), $script, ['throw' => false]);

        if (! $result->successful()) {
            throw new RuntimeException($result->output());
        }
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

    private function readContents(DatabaseConnectionTarget $target, string $path): string
    {
        if ($this->shouldUseLocalFilesystem($target) && is_file($path)) {
            return (string) file_get_contents($path);
        }

        $result = $this->remoteShell->run($this->targetNode($target), sprintf('test -f %1$s && cat %1$s', escapeshellarg($path)), ['throw' => false]);

        return $result->successful() ? $result->stdout : '';
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

    private function shouldUseLocalFilesystem(DatabaseConnectionTarget $target): bool
    {
        $node = $this->targetNode($target);

        return $node->hasActiveRole('gateway');
    }

    private function targetNode(DatabaseConnectionTarget $target): Node
    {
        if ($target->app instanceof App && $target->app->node instanceof Node) {
            return $target->app->node;
        }

        if ($target->workspace instanceof Workspace && $target->workspace->app instanceof App && $target->workspace->app->node instanceof Node) {
            return $target->workspace->app->node;
        }

        throw new RuntimeException('Database connection target has no owning node.');
    }
}
