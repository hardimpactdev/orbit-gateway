<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\AgentIdeWorkspacePathResolver;
use App\Data\AgentIde\WorkspacePathResolution;
use App\Enums\WorkspaceLifecycleStatus;
use App\Exceptions\WorkspaceSetupResolutionFailed;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppAgentIdeDefaults;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

final readonly class WorkspaceSetupTargetResolver
{
    public function __construct(
        private AppAgentIdeDefaults $appAgentIdeDefaults,
        private WorkspaceRoleGuard $roleGuard,
        private Container $container,
    ) {}

    /**
     * @return array{Workspace, App, Node, bool}
     */
    public function resolve(?string $name, ?string $appName, ?string $path, ?string $callerCwd = null, ?Node $callerNode = null): array
    {
        if ($path !== null) {
            return $this->resolveByPath($path, $appName, $name);
        }

        if ($name !== null && $appName !== null) {
            return $this->resolveByName($name, $appName);
        }

        $cwd = $this->normalizePath($callerCwd ?? ((string) getcwd()));
        $outcome = $this->pathOwnership($cwd);

        if ($outcome['type'] === 'workspace') {
            /** @var Workspace $workspace */
            $workspace = $outcome['workspace'];
            $this->assertExplicitMatches($workspace, $appName, null);

            return $this->unwrap($workspace, false);
        }

        if ($outcome['type'] === 'app_root') {
            /** @var App $app */
            $app = $outcome['app'];

            throw new WorkspaceSetupResolutionFailed(
                'workspace.path_is_app_root',
                "The current directory is the '{$app->name}' app root, not a workspace path. Use 'orbit workspace:new' to create a workspace, or change into an existing workspace path and rerun 'orbit workspace:setup'.",
                [
                    'app' => $app->name,
                    'path' => $cwd,
                    'next_command' => 'orbit workspace:new',
                ],
            );
        }

        $apps = $outcome['type'] === 'inside_app'
            ? [$outcome['app']]
            : $this->appsForCaller($callerNode);

        $resolved = $this->probeAdapters($cwd, $apps);

        if ($resolved !== null) {
            [$adapter, $resolution] = $resolved;
            $this->assertAdapterMatchesExplicitInput($resolution, $name, $appName);

            return $this->resolveAdapterWorkspace($adapter, $resolution);
        }

        if ($name !== null) {
            return $this->resolveByName($name, $appName);
        }

        throw new WorkspaceSetupResolutionFailed(
            'validation_failed',
            'Workspace name is required when the current directory cannot resolve a workspace.',
            ['field' => 'name', 'reason' => 'missing_required_input'],
        );
    }

    /**
     * @return array{Workspace, App, Node, bool}
     */
    private function resolveByPath(string $path, ?string $appName, ?string $name = null): array
    {
        $app = $this->resolveApp($appName);

        if (! $app instanceof App) {
            throw new WorkspaceSetupResolutionFailed('validation_failed', 'App not found. Pass --app=<name> explicitly.', ['field' => 'app']);
        }

        $workspaceName = $name ?? basename($path);
        $existing = Workspace::query()
            ->with('app.node')
            ->where('app_id', $app->id)
            ->where('name', $workspaceName)
            ->first();

        if (! $this->pathAllowedForWorkspace($app, $path, $existing)) {
            throw new WorkspaceSetupResolutionFailed(
                'workspace.path_outside_policy',
                "Path {$path} is outside the parent app workspace policy.",
                ['field' => 'path'],
            );
        }

        if ($existing instanceof Workspace) {
            $existing->update(['path' => $path]);

            return $this->unwrap($existing->fresh(['app.node']), false);
        }

        $workspace = Workspace::create([
            'app_id' => $app->id,
            'name' => $workspaceName,
            'path' => $path,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        return $this->unwrap($workspace->load('app.node'), true);
    }

    /**
     * @return array{Workspace, App, Node, bool}
     */
    private function resolveByName(string $name, ?string $appName): array
    {
        $query = Workspace::query()
            ->with(['app.node'])
            ->where('name', $name);

        if ($appName !== null) {
            $query->whereHas('app', fn (Builder $q): Builder => $q->where('name', $appName));
        }

        $workspace = $query->first();

        if (! $workspace instanceof Workspace) {
            throw new WorkspaceSetupResolutionFailed('workspace.not_found', "Workspace '{$name}' not found.", ['field' => 'workspace']);
        }

        return $this->unwrap($workspace, false);
    }

    /**
     * @return array{type: 'workspace', workspace: Workspace}|array{type: 'app_root'|'inside_app', app: App}|array{type: 'unregistered'}
     */
    private function pathOwnership(string $cwd): array
    {
        $workspaces = Workspace::query()
            ->with('app.node')
            ->get()
            ->sortByDesc(fn (Workspace $workspace): int => strlen($this->normalizePath($workspace->path)));

        foreach ($workspaces as $workspace) {
            if (! $this->pathMatches($this->normalizePath($workspace->path), $cwd)) {
                continue;
            }

            if ($this->adapterConfirmsRegisteredWorkspace($workspace, $cwd)) {
                return ['type' => 'workspace', 'workspace' => $workspace];
            }
        }

        $app = App::query()
            ->with('node')
            ->get()
            ->sortByDesc(fn (App $app): int => strlen($this->normalizePath($app->path)))
            ->first(fn (App $app): bool => $this->pathMatches($this->normalizePath($app->path), $cwd));

        if ($app instanceof App) {
            return $this->normalizePath($app->path) === $cwd
                ? ['type' => 'app_root', 'app' => $app]
                : ['type' => 'inside_app', 'app' => $app];
        }

        return ['type' => 'unregistered'];
    }

    private function adapterConfirmsRegisteredWorkspace(Workspace $workspace, string $cwd): bool
    {
        if ($workspace->agent_ide === null || $workspace->agent_ide === 'none') {
            return true;
        }

        $app = $workspace->app;

        if (! $app instanceof App) {
            return false;
        }

        try {
            $resolution = $this->pathResolver()->resolve($workspace->agent_ide, $app, $cwd);
        } catch (Throwable $exception) {
            throw new WorkspaceSetupResolutionFailed(
                'workspace.agent_ide_path_resolution_failed',
                "The '{$workspace->agent_ide}' adapter could not resolve the current directory to a managed workspace.",
                [
                    'adapter' => $workspace->agent_ide,
                    'path' => $cwd,
                    'reason' => $exception->getMessage() !== '' ? $exception->getMessage() : 'adapter_unreachable',
                ],
            );
        }

        if (! $resolution instanceof WorkspacePathResolution) {
            return false;
        }

        if ($resolution->appSlug !== $app->name || $resolution->workspaceName !== $workspace->name) {
            return false;
        }

        if ($this->normalizePath($resolution->path) !== $this->normalizePath($workspace->path)) {
            return false;
        }

        return $workspace->agent_ide_workspace_id === null
            || $workspace->agent_ide_workspace_id === $resolution->adapterWorkspaceId;
    }

    /**
     * @param  list<App|mixed>  $apps
     * @return array{string, WorkspacePathResolution}|null
     */
    private function probeAdapters(string $cwd, array $apps): ?array
    {
        $matches = [];

        foreach ($apps as $app) {
            if (! $app instanceof App) {
                continue;
            }

            $adapter = $this->appAgentIdeDefaults->payloadFor($app)['effective_adapter'];

            if (! is_string($adapter) || $adapter === '') {
                continue;
            }

            try {
                $match = $this->pathResolver()->resolve($adapter, $app, $cwd);
            } catch (Throwable $exception) {
                throw new WorkspaceSetupResolutionFailed(
                    'workspace.agent_ide_path_resolution_failed',
                    "The '{$adapter}' adapter could not resolve the current directory to a managed workspace.",
                    [
                        'adapter' => $adapter,
                        'path' => $cwd,
                        'reason' => $exception->getMessage() !== '' ? $exception->getMessage() : 'adapter_unreachable',
                    ],
                );
            }

            if ($match instanceof WorkspacePathResolution) {
                $matches[$adapter] = $match;
            }
        }

        if (count($matches) > 1) {
            $adapters = array_keys($matches);
            sort($adapters);

            throw new WorkspaceSetupResolutionFailed(
                'validation_failed',
                'Multiple Agent IDE adapters resolved the current directory. Pass --app=<slug> to disambiguate.',
                ['field' => 'app', 'reason' => 'adapter_ambiguous', 'adapters' => $adapters],
            );
        }

        if ($matches === []) {
            return null;
        }

        $adapter = array_key_first($matches);

        return [$adapter, $matches[$adapter]];
    }

    /**
     * @return array{Workspace, App, Node, bool}
     */
    private function resolveAdapterWorkspace(string $adapter, WorkspacePathResolution $resolution): array
    {
        $app = $this->resolveApp($resolution->appSlug);

        if (! $app instanceof App) {
            throw new WorkspaceSetupResolutionFailed('validation_failed', 'Adapter resolved an unknown parent app.', ['field' => 'app']);
        }

        $workspace = Workspace::query()
            ->with('app.node')
            ->where('app_id', $app->id)
            ->where('name', $resolution->workspaceName)
            ->first();

        $isAdoption = ! $workspace instanceof Workspace;

        if ($workspace instanceof Workspace) {
            $workspace->update([
                'path' => $resolution->path,
                'agent_ide' => $adapter,
                'agent_ide_workspace_id' => $resolution->adapterWorkspaceId,
            ]);

            return $this->unwrap($workspace->fresh(['app.node']), false);
        }

        $workspace = Workspace::create([
            'app_id' => $app->id,
            'name' => $resolution->workspaceName,
            'path' => $resolution->path,
            'agent_ide' => $adapter,
            'agent_ide_workspace_id' => $resolution->adapterWorkspaceId,
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);

        return $this->unwrap($workspace->load('app.node'), $isAdoption);
    }

    /**
     * @return list<App>
     */
    private function appsForCaller(?Node $callerNode): array
    {
        $query = App::query()->with('node');

        if ($callerNode instanceof Node && app(NodeRoleAssignments::class)->nodeHasActiveAppHostRole($callerNode)) {
            $query->where('node_id', $callerNode->id);
        }

        return $query->get()->all();
    }

    private function assertExplicitMatches(Workspace $workspace, ?string $appName, ?string $path): void
    {
        if ($appName !== null && $workspace->app?->name !== $appName) {
            throw new WorkspaceSetupResolutionFailed('validation_failed', 'The --app value does not match the workspace resolved from the current directory.', ['field' => 'app']);
        }

        if ($path !== null && $this->normalizePath($workspace->path) !== $this->normalizePath($path)) {
            throw new WorkspaceSetupResolutionFailed('validation_failed', 'The --path value does not match the workspace resolved from the current directory.', ['field' => 'path']);
        }
    }

    private function assertAdapterMatchesExplicitInput(WorkspacePathResolution $resolution, ?string $name, ?string $appName): void
    {
        if ($name !== null && $name !== $resolution->workspaceName) {
            throw new WorkspaceSetupResolutionFailed('validation_failed', 'The workspace name does not match the Agent IDE adapter resolution.', ['field' => 'name', 'reason' => 'adapter_mismatch']);
        }

        if ($appName !== null && $appName !== $resolution->appSlug) {
            throw new WorkspaceSetupResolutionFailed('validation_failed', 'The --app value does not match the Agent IDE adapter resolution.', ['field' => 'app', 'reason' => 'adapter_mismatch']);
        }
    }

    /**
     * @return array{Workspace, App, Node, bool}
     */
    private function unwrap(Workspace $workspace, bool $isAdoption): array
    {
        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new WorkspaceSetupResolutionFailed('validation_failed', "App not found for workspace '{$workspace->name}'.", ['field' => 'app']);
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            throw new WorkspaceSetupResolutionFailed('validation_failed', "Node not found for workspace '{$workspace->name}'.", ['field' => 'app']);
        }

        try {
            $this->roleGuard->ensureAppSupportsWorkspaces($app);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            throw new WorkspaceSetupResolutionFailed($exception->errorCode(), $exception->getMessage(), $exception->meta);
        }

        return [$workspace, $app, $node, $isAdoption];
    }

    private function resolveApp(?string $appName): ?App
    {
        if ($appName === null) {
            return null;
        }

        return App::query()->with('node')->where('name', $appName)->first();
    }

    private function pathAllowedForWorkspace(App $app, string $path, ?Workspace $workspace): bool
    {
        if ($workspace instanceof Workspace && $workspace->agent_ide !== null && $workspace->agent_ide !== 'none') {
            return true;
        }

        $appPath = rtrim($this->normalizePath($app->path), '/');

        return str_starts_with($this->normalizePath($path), "{$appPath}/.worktrees/");
    }

    private function normalizePath(string $path): string
    {
        return rtrim(realpath($path) ?: $path, '/') ?: '/';
    }

    private function pathMatches(string $candidate, string $cwd): bool
    {
        return $candidate === $cwd || str_starts_with($cwd, "{$candidate}/");
    }

    private function pathResolver(): AgentIdeWorkspacePathResolver
    {
        return $this->container->make(AgentIdeWorkspacePathResolver::class);
    }
}
