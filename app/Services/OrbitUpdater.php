<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class OrbitUpdater
{
    public function pullSource(): ProcessResult
    {
        return Process::path(repo_path())
            ->timeout(60)
            ->run('git pull --ff-only');
    }

    public function installDependencies(): ProcessResult
    {
        return Process::path(repo_path())
            ->timeout(120)
            ->run(['docker', 'exec', 'orbit-gateway', 'composer', '--working-dir=apps/gateway', 'install', '--no-interaction']);
    }

    public function runMigrations(): ProcessResult
    {
        return Process::path(repo_path())
            ->timeout(60)
            ->run(['docker', 'exec', 'orbit-gateway', 'php', 'apps/gateway/artisan', 'migrate', '--force']);
    }

    public function updateLocal(): ProcessResult
    {
        $result = $this->pullSource();

        if (! $result->successful()) {
            return $result;
        }

        $result = $this->installDependencies();

        if (! $result->successful()) {
            return $result;
        }

        return $this->runMigrations();
    }

    public function updateRemote(Node $node): RemoteShellResult
    {
        $result = $this->pullRemoteSource($node);

        if (! $result->successful()) {
            return $result;
        }

        $result = $this->installRemoteDependencies($node);

        if (! $result->successful()) {
            return $result;
        }

        return $this->runRemoteMigrations($node);
    }

    public function pullRemoteSource(Node $node): RemoteShellResult
    {
        return $this->runRemote($node, 'git pull --ff-only', 60);
    }

    public function installRemoteDependencies(Node $node): RemoteShellResult
    {
        return $this->runRemote($node, 'docker exec orbit-gateway composer --working-dir=apps/gateway install --no-interaction', 120);
    }

    public function runRemoteMigrations(Node $node): RemoteShellResult
    {
        return $this->runRemote($node, 'docker exec orbit-gateway php apps/gateway/artisan migrate --force', 60);
    }

    public function remoteStageScript(string $stage): string
    {
        return match ($stage) {
            'pulling_source' => 'git pull --ff-only',
            'installing_dependencies' => 'docker exec orbit-gateway composer --working-dir=apps/gateway install --no-interaction',
            'running_migrations' => 'docker exec orbit-gateway php apps/gateway/artisan migrate --force',
            default => throw new \InvalidArgumentException("Unknown remote update stage [{$stage}]."),
        };
    }

    public function remoteStageTimeout(string $stage): int
    {
        return match ($stage) {
            'pulling_source', 'running_migrations' => 60,
            'installing_dependencies' => 120,
            default => throw new \InvalidArgumentException("Unknown remote update stage [{$stage}]."),
        };
    }

    public function updateCommand(): string
    {
        return 'git pull --ff-only && docker exec orbit-gateway composer --working-dir=apps/gateway install --no-interaction && docker exec orbit-gateway php apps/gateway/artisan migrate --force';
    }

    private function runRemote(Node $node, string $script, int $timeout): RemoteShellResult
    {
        return app(RemoteShell::class)->run($node, $script, [
            'cwd' => $node->orbit_path,
            'timeout' => $timeout,
        ]);
    }
}
