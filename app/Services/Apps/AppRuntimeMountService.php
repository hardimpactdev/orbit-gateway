<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\AppRuntimeMount;
use App\Models\Node;
use Illuminate\Database\Eloquent\Collection;

final readonly class AppRuntimeMountService
{
    /**
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    public function mountsForRuntime(App $app): array
    {
        if (! $this->isSupportedAppDevPhpApp($app)) {
            return [];
        }

        $app->loadMissing('runtimeMounts');

        return $app->runtimeMounts
            ->map(fn (AppRuntimeMount $mount): array => $this->mountPayload($mount))
            ->values()
            ->all();
    }

    /**
     * @return array{action: string, mount: AppRuntimeMount, mounts: Collection<int, AppRuntimeMount>}
     */
    public function add(App $app, string $source, string $target, bool $readOnly = true): array
    {
        [$source, $target] = $this->validateIntent($app, $source, $target);

        $mount = $app->runtimeMounts()->firstOrNew(['target' => $target]);
        $exists = $mount->exists;

        $mount->source = $source;
        $mount->target = $target;
        $mount->read_only = $readOnly;

        $changed = ! $exists || $mount->isDirty(['source', 'target', 'read_only']);
        $mount->save();

        $app->unsetRelation('runtimeMounts');

        return [
            'action' => $exists
                ? ($changed ? 'updated' : 'unchanged')
                : 'created',
            'mount' => $mount->refresh(),
            'mounts' => $this->list($app),
        ];
    }

    /**
     * @return array{action: string, mount: AppRuntimeMount|null, mounts: Collection<int, AppRuntimeMount>}
     */
    public function remove(App $app, string $target): array
    {
        $target = $this->normalizePath($target, 'target');

        $mount = $app->runtimeMounts()
            ->where('target', $target)
            ->first();

        if (! $mount instanceof AppRuntimeMount) {
            return [
                'action' => 'missing',
                'mount' => null,
                'mounts' => $this->list($app),
            ];
        }

        $mount->delete();
        $app->unsetRelation('runtimeMounts');

        return [
            'action' => 'removed',
            'mount' => $mount,
            'mounts' => $this->list($app),
        ];
    }

    /**
     * @return Collection<int, AppRuntimeMount>
     */
    public function list(App $app): Collection
    {
        return $app->runtimeMounts()
            ->orderBy('target')
            ->get();
    }

    /**
     * @return array{source: string, target: string, read_only: bool}
     */
    public function mountPayload(AppRuntimeMount $mount): array
    {
        return [
            'source' => $mount->source,
            'target' => $mount->target,
            'read_only' => (bool) $mount->read_only,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function validateIntent(App $app, string $source, string $target): array
    {
        $this->assertSupportedApp($app);

        $source = $this->normalizePath($source, 'source');
        $target = $this->normalizePath($target, 'target');

        $nodeUser = $this->nodeUser($app);
        $home = "/home/{$nodeUser}";

        if (! $this->isSameOrChild($source, $home)) {
            throw $this->validationFailure(
                'source_outside_app_dev_home',
                "Runtime mount sources must live under {$home}.",
                ['source' => $source, 'home' => $home],
            );
        }

        if ($source === $home || $this->isSensitiveHomeSource($source, $home)) {
            throw $this->validationFailure(
                'source_sensitive',
                'Runtime mount sources cannot expose sensitive home directories or credential files.',
                ['source' => $source],
            );
        }

        if ($this->isReservedTarget($app, $target)) {
            throw $this->validationFailure(
                'target_reserved',
                "Runtime mount target '{$target}' is reserved by Orbit.",
                ['target' => $target],
            );
        }

        return [$source, $target];
    }

    private function assertSupportedApp(App $app): void
    {
        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            throw $this->validationFailure(
                'app_runtime_kind_not_php',
                "App '{$app->name}' does not use the PHP runtime.",
                ['app' => $app->name, 'runtime_kind' => $app->runtime_kind->value],
            );
        }

        if (! $this->isAppDevApp($app)) {
            throw $this->validationFailure(
                'app_mounts_app_dev_only',
                'Configurable app runtime mounts are currently supported for app-dev apps only.',
                ['app' => $app->name],
            );
        }
    }

    private function isSupportedAppDevPhpApp(App $app): bool
    {
        return $app->runtime_kind === AppRuntimeKind::Php && $this->isAppDevApp($app);
    }

    private function isAppDevApp(App $app): bool
    {
        $app->loadMissing('node.roleAssignments');

        return $app->node instanceof Node
            && $app->node->hasActiveRole(NodeRoleName::AppDevelopment->value);
    }

    private function nodeUser(App $app): string
    {
        $app->loadMissing('node');

        $nodeUser = trim((string) ($app->node?->user ?: 'orbit'));

        if ($nodeUser === '' || preg_match('/^[A-Za-z0-9._-]+$/', $nodeUser) !== 1) {
            throw $this->validationFailure(
                'node_user_invalid',
                "App '{$app->name}' has an invalid runtime user.",
                ['app' => $app->name],
            );
        }

        return $nodeUser;
    }

    private function normalizePath(string $path, string $field): string
    {
        $path = trim($path);

        if ($path === '') {
            throw $this->validationFailure(
                "{$field}_required",
                ucfirst($field).' path is required.',
                ['field' => $field],
            );
        }

        if (! str_starts_with($path, '/')) {
            throw $this->validationFailure(
                "{$field}_must_be_absolute",
                ucfirst($field).' path must be absolute.',
                ['field' => $field, $field => $path],
            );
        }

        if (preg_match('/[\x00\r\n]/', $path) === 1) {
            throw $this->validationFailure(
                "{$field}_invalid",
                ucfirst($field).' path contains invalid characters.',
                ['field' => $field],
            );
        }

        $segments = explode('/', $path);

        if (in_array('..', $segments, true)) {
            throw $this->validationFailure(
                "{$field}_contains_parent_segment",
                ucfirst($field).' path cannot contain parent directory segments.',
                ['field' => $field, $field => $path],
            );
        }

        while (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = substr($path, 0, -1);
        }

        return $path;
    }

    private function isSensitiveHomeSource(string $source, string $home): bool
    {
        $sensitivePaths = [
            "{$home}/.aws",
            "{$home}/.config",
            "{$home}/.composer/auth.json",
            "{$home}/.gnupg",
            "{$home}/.netrc",
            "{$home}/.npmrc",
            "{$home}/.ssh",
        ];

        return array_any(
            $sensitivePaths,
            fn (string $sensitivePath): bool => $this->isSameOrChild($source, $sensitivePath),
        );
    }

    private function isReservedTarget(App $app, string $target): bool
    {
        $reservedTargets = [
            AppRuntimeContainer::SourceTarget,
            AppRuntimeContainer::PhpIniMountTarget,
            AppDevelopmentPackagesMount::Target,
            '/config',
            '/data',
        ];

        $appPath = rtrim((string) $app->path, '/');

        if ($appPath !== '') {
            $reservedTargets[] = $appPath;
        }

        return array_any(
            $reservedTargets,
            fn (string $reservedTarget): bool => $this->isSameOrChild($target, $reservedTarget),
        );
    }

    private function isSameOrChild(string $path, string $parent): bool
    {
        $parent = rtrim($parent, '/');

        return $path === $parent || str_starts_with($path, "{$parent}/");
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function validationFailure(string $reason, string $message, array $meta = []): AppRuntimeMountValidationException
    {
        return new AppRuntimeMountValidationException(
            reason: $reason,
            message: $message,
            meta: [
                'reason' => $reason,
                ...$meta,
            ],
        );
    }
}
