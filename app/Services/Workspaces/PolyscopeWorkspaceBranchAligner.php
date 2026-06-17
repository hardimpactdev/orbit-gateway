<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use JsonException;
use Throwable;

final readonly class PolyscopeWorkspaceBranchAligner
{
    private const string WorkspaceAdapterUpdateCommand = 'internal:workspace-adapter:update';

    /**
     * @var list<string>
     */
    private const array SafeUpdateErrorCodes = [
        'missing_token',
        'invalid_token',
        'validation_failed',
        'home_directory_unavailable',
        'database_missing',
        'database_unwritable',
        'workspace_not_found',
        'update_failed',
    ];

    public function __construct(
        private RemoteShell $remoteShell,
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function align(Node $node, string $workspaceId, string $path, string $name): void
    {
        $this->renameHostGitBranch($node, $workspaceId, $path, $name);
        $this->updateAdapterWorkspaceBranch($node, $workspaceId, $path, $name);
    }

    private function renameHostGitBranch(Node $node, string $workspaceId, string $path, string $name): void
    {
        try {
            $result = $this->remoteShell->run($node, $this->script(), [
                'metadata' => [
                    'ORBIT_POLYSCOPE_WORKSPACE_PATH' => $path,
                    'ORBIT_WORKSPACE_NAME' => $name,
                ],
                'timeout' => 30,
            ]);
        } catch (Throwable) {
            throw $this->alignmentFailed($node, $workspaceId, $path, $name, 'branch_rename_failed');
        }

        if ($result->successful()) {
            return;
        }

        throw $this->alignmentFailed($node, $workspaceId, $path, $name, 'branch_rename_failed');
    }

    private function updateAdapterWorkspaceBranch(Node $node, string $workspaceId, string $path, string $name): void
    {
        try {
            $result = $this->localExecutor->runInternal(
                node: $node,
                commandName: self::WorkspaceAdapterUpdateCommand,
                arguments: [],
                commandOptions: [
                    'adapter' => 'polyscope',
                    'update' => 'workspace-branch',
                    'workspace-id' => $workspaceId,
                    'branch' => $name,
                ],
                transportOptions: ['timeout' => 30],
            );
        } catch (Throwable) {
            throw $this->alignmentFailed($node, $workspaceId, $path, $name, 'workspace_adapter_update_failed');
        }

        if ($result->successful()) {
            return;
        }

        $meta = $this->failureMeta($node, $workspaceId, $path, $name, 'workspace_adapter_update_failed');
        $adapterErrorCode = $this->safeUpdateErrorCode($result);

        if ($adapterErrorCode !== null) {
            $meta['adapter_error_code'] = $adapterErrorCode;
        }

        throw new WorkspaceCreateFailed(
            'workspace.agent_ide_create_failed',
            'Polyscope workspace was created but could not be renamed.',
            $meta,
        );
    }

    private function script(): string
    {
        return <<<'SH'
set -eu

workspace_path="${ORBIT_POLYSCOPE_WORKSPACE_PATH:-}"
workspace_name="${ORBIT_WORKSPACE_NAME:-}"

if [ -z "$workspace_path" ] || [ -z "$workspace_name" ]; then
    echo "Polyscope workspace path and target name are required." >&2
    exit 2
fi

if [ ! -d "$workspace_path" ]; then
    echo "Polyscope workspace path is missing." >&2
    exit 2
fi

current_branch="$(git -C "$workspace_path" branch --show-current)"

if [ "$current_branch" != "$workspace_name" ]; then
    if git -C "$workspace_path" rev-parse --verify --quiet "$workspace_name" >/dev/null 2>&1; then
        echo "Git branch already exists." >&2
        exit 2
    fi

    git -C "$workspace_path" branch -m "$workspace_name"
fi

printf '%s\n' '{"branch_renamed":true}'
SH;
    }

    private function alignmentFailed(
        Node $node,
        string $workspaceId,
        string $path,
        string $name,
        string $reason,
    ): WorkspaceCreateFailed {
        return new WorkspaceCreateFailed(
            'workspace.agent_ide_create_failed',
            'Polyscope workspace was created but could not be renamed.',
            $this->failureMeta($node, $workspaceId, $path, $name, $reason),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function failureMeta(Node $node, string $workspaceId, string $path, string $name, string $reason): array
    {
        return [
            'adapter' => 'polyscope',
            'node' => $node->name,
            'workspace_id' => $workspaceId,
            'path' => $path,
            'workspace' => $name,
            'reason' => $reason,
        ];
    }

    private function safeUpdateErrorCode(RemoteShellResult $result): ?string
    {
        try {
            $decoded = json_decode(trim($result->stdout), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! is_array($decoded['error'] ?? null)) {
            return null;
        }

        $code = $decoded['error']['code'] ?? null;

        if (! is_string($code)) {
            return null;
        }

        $code = trim($code);

        return in_array($code, self::SafeUpdateErrorCodes, true) ? $code : null;
    }
}
