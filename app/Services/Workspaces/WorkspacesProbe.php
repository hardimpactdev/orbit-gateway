<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpRuntimeCatalog;

final readonly class WorkspacesProbe
{
    public function __construct(
        private ?RemoteShell $remoteShell = null,
        private ?WorkspaceRuntimeUser $workspaceRuntimeUser = null,
        private ?NodeRoleAssignments $nodeRoleAssignments = null,
        private ?PhpRuntimeCatalog $phpRuntimeCatalog = null,
    ) {}

    public function key(): string
    {
        return 'workspace';
    }

    public function label(): string
    {
        return 'Workspaces';
    }

    public function introspect(Workspace $workspace): ProbeSnapshot
    {
        $workspace->loadMissing('app.node');

        if (! $workspace->app instanceof App || ! $workspace->app->node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        $spec = [
            'name' => $workspace->name,
            'path' => $workspace->path,
            'php_version' => $workspace->effectivePhpVersion(),
            'runtime_user' => $this->workspaceRuntimeUser()->forWorkspace($workspace),
        ];

        $script = <<<'SH'
set -eu

name=__NAME__
path=__PATH__
runtime_user=__RUNTIME_USER__

path_exists=0
path_usable=0
system_user_exists=0
fs_permissions_ok=0

if [ -d "$path" ]; then
    path_exists=1

    if [ -r "$path" ] && [ -x "$path" ]; then
        path_usable=1
    fi
fi

if [ -n "$runtime_user" ] && id -u "$runtime_user" >/dev/null 2>&1; then
    system_user_exists=1
fi

owner=''
mode=''

if [ "$path_exists" = "1" ]; then
    owner=$(stat -c '%U' "$path" 2>/dev/null || stat -f '%Su' "$path" 2>/dev/null || printf '')
    mode=$(stat -c '%a' "$path" 2>/dev/null || stat -f '%Lp' "$path" 2>/dev/null || printf '')
fi

if [ "$path_exists" = "1" ] && [ -n "$runtime_user" ] && [ "$owner" = "$runtime_user" ] && [ -n "$mode" ]; then
    group_digit=${mode%?}
    group_digit=${group_digit#${group_digit%?}}
    other_digit=${mode#${mode%?}}

    case "$group_digit:$other_digit" in
        0:0|0:1|0:4|0:5|1:0|1:1|1:4|1:5|4:0|4:1|4:4|4:5|5:0|5:1|5:4|5:5)
            fs_permissions_ok=1
            ;;
    esac
fi

printf '%s\t%s\t%s\t%s\t%s\n' "$name" "$path_exists" "$path_usable" "$system_user_exists" "$fs_permissions_ok"
SH;

        $script = strtr($script, [
            '__NAME__' => escapeshellarg($spec['name']),
            '__PATH__' => escapeshellarg($spec['path']),
            '__RUNTIME_USER__' => escapeshellarg($spec['runtime_user']),
        ]);

        $result = ($this->remoteShell ?? app(RemoteShell::class))->run($workspace->app->node, $script, [
            'throw' => true,
            'input' => (string) json_encode($spec, JSON_THROW_ON_ERROR),
        ]);

        $items = [];

        foreach (explode("\n", rtrim($result->stdout, "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line);

            if (count($parts) !== 5) {
                continue;
            }

            [$name, $pathExists, $pathUsable, $systemUserExists, $fsPermissionsOk] = $parts;

            $items[$name] = [
                'path_exists' => $pathExists === '1',
                'path_usable' => $pathUsable === '1',
                'system_user_exists' => $systemUserExists === '1',
                'fs_permissions_ok' => $fsPermissionsOk === '1',
            ];
        }

        return new ProbeSnapshot($items);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(Workspace $workspace, ProbeSnapshot $snapshot): array
    {
        $drift = [];

        $drift = array_merge($drift, $this->checkRecordCompleteness($workspace));
        $drift = array_merge($drift, $this->checkParentApp($workspace));
        $drift = array_merge($drift, $this->checkSourcePath($workspace, $snapshot));
        $drift = array_merge($drift, $this->checkPhpRuntime($workspace, $snapshot));
        $drift = array_merge($drift, $this->checkDevelopmentSecurity($workspace, $snapshot));

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(Workspace $workspace): array
    {
        if (
            ! is_string($workspace->name)
            || $workspace->name === ''
            || ! is_int($workspace->app_id)
            || ! is_string($workspace->path)
            || $workspace->path === ''
            || ! is_string($workspace->effectivePhpVersion())
            || $workspace->effectivePhpVersion() === ''
            || ! is_string($workspace->getRawOriginal('lifecycle_status'))
            || $workspace->getRawOriginal('lifecycle_status') === ''
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Workspace record for {$workspace->name} is missing required fields.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkPhpRuntime(Workspace $workspace, ProbeSnapshot $snapshot): array
    {
        $workspace->loadMissing('app');

        if (! $workspace->app instanceof App || $workspace->app->runtime_kind !== AppRuntimeKind::Php) {
            return [];
        }

        if (! $this->phpRuntimeCatalog()->supports($workspace->effectivePhpVersion())) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "PHP {$workspace->effectivePhpVersion()} is not a supported FrankenPHP runtime image for workspace {$workspace->name}.",
                    detail: [
                        'php_version' => $workspace->effectivePhpVersion(),
                    ],
                ),
            ];
        }

        $observed = $snapshot->get($workspace->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        if (($observed['docker_available'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "Docker is not available to serve PHP {$workspace->effectivePhpVersion()} for workspace {$workspace->name} on the parent app node.",
                    detail: [
                        'php_version' => $workspace->effectivePhpVersion(),
                    ],
                ),
            ];
        }

        if (($observed['runtime_image_probe_failed'] ?? null) === true) {
            return [];
        }

        if (($observed['runtime_image_available'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.php_version_unavailable',
                    kind: DriftKind::Missing,
                    summary: "FrankenPHP runtime image for PHP {$workspace->effectivePhpVersion()} is not available on the parent app node for workspace {$workspace->name}.",
                    detail: [
                        'php_version' => $workspace->effectivePhpVersion(),
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkDevelopmentSecurity(Workspace $workspace, ProbeSnapshot $snapshot): array
    {
        $workspace->loadMissing('app.node');

        if (! $workspace->app instanceof App || ! $workspace->app->node instanceof Node) {
            return [];
        }

        if ($this->nodeRoleAssignments()->nodeHasActiveRole($workspace->app->node, 'app-prod')) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.unsupported_for_production',
                    kind: DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} belongs to a production app role where workspaces are disabled.",
                    detail: [
                        'workspace' => $workspace->name,
                        'app' => $workspace->app->name,
                        'node' => $workspace->app->node->name,
                    ],
                ),
            ];
        }

        if (! $this->nodeRoleAssignments()->nodeHasActiveRole($workspace->app->node, 'app-dev')) {
            return [];
        }

        if ($workspace->app->runtime_kind === AppRuntimeKind::Php) {
            return [];
        }

        $observed = $snapshot->get($workspace->name);

        if ($observed === null || ($observed['path_exists'] ?? null) === false) {
            return [];
        }

        if (! array_key_exists('system_user_exists', $observed)) {
            return [];
        }

        $drift = [];

        if (($observed['system_user_exists'] ?? null) === false) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'workspace.security.system_user',
                kind: DriftKind::Missing,
                summary: "Workspace {$workspace->name} is missing its expected runtime user.",
                detail: [
                    'workspace' => $workspace->name,
                    'runtime_user' => $this->workspaceRuntimeUser()->forWorkspace($workspace),
                ],
            );
        }

        if (($observed['fs_permissions_ok'] ?? null) === false) {
            $drift[] = new DriftEntry(
                family: $this->key(),
                key: 'workspace.security.fs_permissions',
                kind: DriftKind::Divergent,
                summary: "Workspace {$workspace->name} filesystem permissions do not match runtime policy.",
                detail: [
                    'workspace' => $workspace->name,
                    'path' => $workspace->path,
                    'runtime_user' => $this->workspaceRuntimeUser()->forWorkspace($workspace),
                ],
            );
        }

        return $drift;
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkParentApp(Workspace $workspace): array
    {
        $workspace->loadMissing('app.node');

        if (! $workspace->app instanceof App) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.parent_app_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} points at a missing parent app.",
                ),
            ];
        }

        if (
            ! $workspace->app->node instanceof Node
            || ! $workspace->app->node->isActive()
            || ! $this->nodeRoleAssignments()->nodeHasActiveAppHostRole($workspace->app->node)
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.parent_app_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} parent app {$workspace->app->name} is not on an active app node.",
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkSourcePath(Workspace $workspace, ProbeSnapshot $snapshot): array
    {
        if ($workspace->path === '') {
            return [];
        }

        if ($this->violatesGenericWorktreePolicy($workspace)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.path_outside_policy',
                    kind: DriftKind::Divergent,
                    summary: "Workspace {$workspace->name} path is outside the generic worktree policy.",
                    detail: [
                        'path' => $workspace->path,
                        'app_path' => $workspace->app?->path,
                    ],
                ),
            ];
        }

        $observed = $snapshot->get($workspace->name);

        if ($observed === null) {
            return [];
        }

        if (($observed['path_exists'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.path_missing',
                    kind: DriftKind::Missing,
                    summary: "Workspace {$workspace->name} path is missing on the parent app node.",
                    detail: [
                        'expected' => $workspace->path,
                    ],
                ),
            ];
        }

        if (($observed['path_usable'] ?? null) === false) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'workspace.path_unusable',
                    kind: DriftKind::Unverifiable,
                    summary: "Workspace {$workspace->name} path exists but is not usable by Orbit.",
                    detail: [
                        'path' => $workspace->path,
                    ],
                ),
            ];
        }

        return [];
    }

    private function violatesGenericWorktreePolicy(Workspace $workspace): bool
    {
        $workspace->loadMissing('app');

        if ($workspace->agent_ide !== null && $workspace->agent_ide !== 'none') {
            return false;
        }

        if (! $workspace->app instanceof App || $workspace->app->path === '') {
            return false;
        }

        $appPath = $this->normalizePath($workspace->app->path);
        $workspacePath = $this->normalizePath($workspace->path);

        return ! str_starts_with($workspacePath, "{$appPath}/.worktrees/");
    }

    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function workspaceRuntimeUser(): WorkspaceRuntimeUser
    {
        return $this->workspaceRuntimeUser ?? app(WorkspaceRuntimeUser::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    private function phpRuntimeCatalog(): PhpRuntimeCatalog
    {
        return $this->phpRuntimeCatalog ?? app(PhpRuntimeCatalog::class);
    }
}
