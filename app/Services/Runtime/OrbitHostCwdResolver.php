<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Models\App;
use App\Models\Workspace;
use Illuminate\Support\Collection;

final class OrbitHostCwdResolver
{
    /**
     * Resolve a host working directory to a managed app and optional workspace.
     *
     * Workspace matches always win over the parent app match so a caller
     * working inside `apps/docs/.worktrees/docs-feature` resolves to the
     * workspace, not the parent app. When the host cwd is unmanaged the
     * resolver returns `null` instead of guessing.
     *
     * `$hostCwd` defaults to `getenv('ORBIT_HOST_CWD')` so callers running
     * inside `orbit-gateway` can resolve the launcher-supplied host cwd
     * without threading it through their own argument lists.
     */
    public function resolve(?string $hostCwd = null): ?OrbitHostCwdContext
    {
        $cwd = $this->normalizeCwd($hostCwd ?? $this->envCwd());

        if ($cwd === null) {
            return null;
        }

        $workspace = $this->bestMatchingWorkspace($cwd);

        if ($workspace instanceof Workspace) {
            $app = $workspace->app;

            if ($app instanceof App) {
                return new OrbitHostCwdContext(
                    app: $app,
                    workspace: $workspace,
                    hostCwd: $cwd,
                );
            }
        }

        $app = $this->bestMatchingApp($cwd);

        if ($app instanceof App) {
            return new OrbitHostCwdContext(
                app: $app,
                workspace: null,
                hostCwd: $cwd,
            );
        }

        return null;
    }

    private function envCwd(): ?string
    {
        $value = getenv('ORBIT_HOST_CWD');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Normalize a host working directory lexically (no filesystem access).
     *
     * The host cwd lives on the caller's machine, not inside `orbit-gateway`,
     * so realpath() is not an option — the gateway may not be able to stat
     * the path at all. Collapse `.` and `..` segments against the path
     * itself, then check the result is still absolute. Paths that escape
     * the filesystem root via `..` resolve to null instead of an unbounded
     * prefix that could let a caller's stray `../..` traverse into a
     * sibling app's source tree.
     */
    private function normalizeCwd(?string $cwd): ?string
    {
        if ($cwd === null) {
            return null;
        }

        $trimmed = trim($cwd);

        if ($trimmed === '' || ! str_starts_with($trimmed, '/')) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    // `..` past the filesystem root is meaningless; treat the
                    // whole path as unresolvable so the caller falls through
                    // to the no-match path.
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function bestMatchingWorkspace(string $cwd): ?Workspace
    {
        $candidates = Workspace::query()
            ->with('app.node')
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->get();

        return $this->longestPathMatch($candidates, $cwd, fn (Workspace $workspace): string => (string) $workspace->path);
    }

    private function bestMatchingApp(string $cwd): ?App
    {
        $candidates = App::query()
            ->with('node')
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->get();

        return $this->longestPathMatch($candidates, $cwd, fn (App $app): string => (string) $app->path);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $candidates
     * @param  callable(TModel): string  $pathFor
     * @return TModel|null
     */
    private function longestPathMatch(Collection $candidates, string $cwd, callable $pathFor): mixed
    {
        $best = null;
        $bestLength = -1;

        foreach ($candidates as $candidate) {
            $candidatePath = rtrim($pathFor($candidate), '/');

            if ($candidatePath === '') {
                continue;
            }

            if (! $this->isInsideOrEqual($cwd, $candidatePath)) {
                continue;
            }

            $length = strlen($candidatePath);

            if ($length > $bestLength) {
                $best = $candidate;
                $bestLength = $length;
            }
        }

        return $best;
    }

    private function isInsideOrEqual(string $cwd, string $candidatePath): bool
    {
        if ($cwd === $candidatePath) {
            return true;
        }

        $prefix = $candidatePath.'/';

        return str_starts_with($cwd, $prefix);
    }
}
