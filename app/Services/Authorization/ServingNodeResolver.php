<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Http\Authorization\ServingNode;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\Workspace;
use App\Services\Runtime\OrbitHostCwdContext;
use App\Services\Runtime\OrbitHostCwdResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

final class ServingNodeResolver
{
    public function resolve(Request $request, ServingNode $servingNode): ?Node
    {
        return match ($servingNode) {
            ServingNode::Gateway => $this->resolveGateway(),
            ServingNode::Target => $this->resolveTarget($request),
            ServingNode::AppOwning => $this->resolveAppOwning($request),
            ServingNode::WorkspaceOwning => $this->resolveWorkspaceOwning($request),
            ServingNode::Caller => $this->resolveCaller($request),
        };
    }

    private function resolveGateway(): ?Node
    {
        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereHas('roleAssignments', fn ($query) => $query
                ->where('role', NodeRoleName::Gateway->value)
                ->where('status', NodeRoleStatus::Active->value))
            ->first();
    }

    private function resolveTarget(Request $request): ?Node
    {
        foreach (['node', 'name', 'target', 'target_node'] as $parameter) {
            $node = $this->nodeFromValue($this->requestValue($request, $parameter));

            if ($node instanceof Node) {
                return $node;
            }
        }

        return null;
    }

    private function resolveAppOwning(Request $request): ?Node
    {
        foreach (['app'] as $parameter) {
            $app = $this->appFromValue($this->requestValue($request, $parameter));

            if ($app instanceof OrbitApp) {
                return $app->node;
            }
        }

        foreach (['process', 'process_name', 'name'] as $parameter) {
            $process = $this->processFromValue(
                value: $this->requestValue($request, $parameter),
                app: $this->appFromValue($this->requestValue($request, 'app')),
            );

            if ($process instanceof OrbitProcess) {
                return $this->appForProcess($process)?->node;
            }
        }

        $app = $this->appFromValue($this->requestValue($request, 'name'));

        if ($app instanceof OrbitApp) {
            return $app->node;
        }

        foreach (['host_cwd', 'path', 'caller_cwd'] as $parameter) {
            $app = $this->appFromPath($this->requestValue($request, $parameter));

            if ($app instanceof OrbitApp) {
                return $app->node;
            }
        }

        return null;
    }

    private function resolveWorkspaceOwning(Request $request): ?Node
    {
        foreach (['workspace', 'name'] as $parameter) {
            $workspace = $this->workspaceFromValue(
                value: $this->requestValue($request, $parameter),
                app: $this->appFromValue($this->requestValue($request, 'app')),
            );

            if ($workspace instanceof Workspace) {
                return $workspace->app?->node;
            }
        }

        foreach (['host_cwd', 'path', 'caller_cwd'] as $parameter) {
            $workspace = $this->workspaceFromPath($this->requestValue($request, $parameter));

            if ($workspace instanceof Workspace) {
                return $workspace->app?->node;
            }
        }

        return null;
    }

    private function resolveCaller(Request $request): ?Node
    {
        $caller = $request->user();

        return $caller instanceof Node ? $caller : null;
    }

    private function requestValue(Request $request, string $name): mixed
    {
        $route = $request->route();

        if ($route instanceof Route && $route->hasParameter($name)) {
            return $route->parameter($name);
        }

        if ($request->request->has($name)) {
            return $request->request->get($name);
        }

        if ($request->query->has($name)) {
            return $request->query->get($name);
        }

        return null;
    }

    private function nodeFromValue(mixed $value): ?Node
    {
        if ($value instanceof Node) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return Node::query()->whereKey($value)->first();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        return Node::query()
            ->where('name', $value)
            ->first();
    }

    private function appFromValue(mixed $value): ?OrbitApp
    {
        if ($value instanceof OrbitApp) {
            $value->loadMissing('node');

            return $value;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return OrbitApp::query()
                ->with('node')
                ->whereKey($value)
                ->first();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $app = OrbitApp::query()
            ->with('node')
            ->where('name', $value)
            ->first();

        if ($app instanceof OrbitApp) {
            return $app;
        }

        $app = OrbitApp::query()
            ->with('node')
            ->where('domain', $value)
            ->first();

        if ($app instanceof OrbitApp) {
            return $app;
        }

        return OrbitApp::query()
            ->with('node')
            ->get()
            ->first(fn (OrbitApp $app): bool => $app->url() === "https://{$value}" || $app->url() === $value);
    }

    private function processFromValue(mixed $value, ?OrbitApp $app = null): ?OrbitProcess
    {
        if ($value instanceof OrbitProcess) {
            $value->loadMissing('owner');

            return $value;
        }

        if (! is_int($value) && (! is_string($value) || $value === '')) {
            return null;
        }

        return OrbitProcess::query()
            ->with('owner')
            ->when($app instanceof OrbitApp, fn ($query) => $query->ownedBy($app))
            ->when(
                is_int($value) || ctype_digit((string) $value),
                fn ($query) => $query->whereKey($value),
                fn ($query) => $query->where('name', $value),
            )
            ->first();
    }

    private function appForProcess(OrbitProcess $process): ?OrbitApp
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof OrbitApp) {
            $process->owner->loadMissing('node');

            return $process->owner;
        }

        if ($process->owner instanceof Workspace) {
            $process->owner->loadMissing('app.node');

            return $process->owner->app;
        }

        return null;
    }

    private function workspaceFromValue(mixed $value, ?OrbitApp $app = null): ?Workspace
    {
        if ($value instanceof Workspace) {
            $value->loadMissing('app.node');

            return $value;
        }

        if (! is_int($value) && (! is_string($value) || $value === '')) {
            return null;
        }

        $query = Workspace::query()
            ->with('app.node')
            ->when($app instanceof OrbitApp, fn ($query) => $query->where('app_id', $app->id))
            ->when(
                is_int($value) || ctype_digit((string) $value),
                fn ($query) => $query->whereKey($value),
                fn ($query) => $query->where('name', $value),
            );

        $matches = $query->limit(2)->get();

        if ($matches->count() !== 1) {
            return null;
        }

        return $matches->first();
    }

    private function workspaceFromPath(mixed $value): ?Workspace
    {
        $context = $this->resolveCwd($value);

        return $context?->workspace;
    }

    private function appFromPath(mixed $value): ?OrbitApp
    {
        $context = $this->resolveCwd($value);

        if ($context === null) {
            return null;
        }

        // Workspace match wins over parent-app match when the cwd is inside
        // a workspace tree. AppOwning authorization mirrors that preference:
        // a path inside a workspace authorizes against the workspace's
        // parent app (which is the same node), not "some random app whose
        // path happens to be a string prefix".
        return $context->app;
    }

    private function resolveCwd(mixed $value): ?OrbitHostCwdContext
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return app(OrbitHostCwdResolver::class)->resolve($value);
    }
}
