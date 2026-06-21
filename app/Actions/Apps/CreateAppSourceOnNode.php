<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Support\GitRepositoryReference;

final readonly class CreateAppSourceOnNode
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array{path: string, result: RemoteShellResult}
     */
    public function handle(Node $node, string $name, ?string $repository, ?string $domain = null): array
    {
        $path = $this->appPath($node, $name, $domain);
        $script = $repository === null
            ? $this->createDirectoryCommand($node, $path)
            : $this->cloneRepositoryCommand($node, $repository, $path);

        return [
            'path' => $path,
            'result' => $this->remoteShell->run($node, $script),
        ];
    }

    private function appPath(Node $node, string $name, ?string $domain): string
    {
        if (is_string($domain) && $domain !== '') {
            return "/home/{$name}/app";
        }

        $user = $node->user ?: 'orbit';
        $home = $user === 'root' ? '/root' : "/home/{$user}";

        return "{$home}/apps/{$name}";
    }

    private function createDirectoryCommand(Node $node, string $path): string
    {
        $user = $node->user ?: 'orbit';
        $group = $user;

        return sprintf(
            'sudo install -d -m 755 -o %s -g %s %s %s',
            escapeshellarg($user),
            escapeshellarg($group),
            escapeshellarg(dirname($path)),
            escapeshellarg($path),
        );
    }

    private function cloneRepositoryCommand(Node $node, string $repository, string $path): string
    {
        $user = $node->user ?: 'orbit';
        $group = $user;

        return sprintf(
            'sudo install -d -m 755 -o %s -g %s %s && %s',
            escapeshellarg($user),
            escapeshellarg($group),
            escapeshellarg(dirname($path)),
            GitRepositoryReference::cloneCommand($repository, $path),
        );
    }
}
