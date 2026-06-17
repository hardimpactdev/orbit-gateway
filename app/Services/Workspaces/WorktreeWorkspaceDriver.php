<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Contracts\WorkspaceSourceDriver;
use App\Data\Workspaces\WorkspaceProvisionResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\App;
use App\Models\Node;

final readonly class WorktreeWorkspaceDriver implements WorkspaceSourceDriver
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    public function create(App $app, Node $node, string $name, string $base): WorkspaceProvisionResult
    {
        $path = $this->workspacePath($app, $name);
        $result = $this->remoteShell->run($node, $this->worktreeScript($app, $name, $base), ['timeout' => 300]);

        if (! $result->successful()) {
            $output = trim($result->output());

            throw new WorkspaceCreateFailed(
                'workspace.source_create_failed',
                $output !== '' ? "Failed to create git worktree: {$output}" : 'Failed to create git worktree.',
                [
                    'driver' => 'worktree',
                    'node' => $node->name,
                    'app' => $app->name,
                    'workspace' => $name,
                    'path' => $path,
                ],
            );
        }

        return new WorkspaceProvisionResult(
            name: $name,
            path: $path,
        );
    }

    private function workspacePath(App $app, string $workspaceName): string
    {
        return rtrim($app->path, '/').'/.worktrees/'.$workspaceName;
    }

    private function worktreeScript(App $app, string $workspaceName, string $base): string
    {
        return sprintf(
            <<<'SH'
set -Eeuo pipefail
app_path=%s
workspace_name=%s
relative_path=".worktrees/${workspace_name}"
workspace_path="${app_path}/${relative_path}"
base_ref=%s

if [ -e "$workspace_path" ]; then
    echo "workspace path already exists: $workspace_path" >&2
    exit 2
fi

mkdir -p "${app_path}/.worktrees"
git -C "$app_path" worktree add "$relative_path" -b "$workspace_name" "$base_ref"
SH,
            escapeshellarg(rtrim($app->path, '/')),
            escapeshellarg($workspaceName),
            escapeshellarg($base),
        );
    }
}
