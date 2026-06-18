<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\WorkspaceRuntimeUserResolver;
use App\Models\Workspace;
use Illuminate\Support\Str;

final readonly class WorkspaceRuntimeUser implements WorkspaceRuntimeUserResolver
{
    public function forWorkspace(Workspace $workspace): string
    {
        $workspace->loadMissing('app');

        $appName = $workspace->app?->name ?: 'app';
        $slug = Str::of("{$appName}-{$workspace->name}")->slug('-')->lower()->toString();
        $slug = $slug !== '' ? $slug : 'workspace';
        $suffix = substr(hash('sha1', $slug), 0, 6);
        $base = substr($slug, 0, 16);

        return "orbit-ws-{$base}-{$suffix}";
    }
}
