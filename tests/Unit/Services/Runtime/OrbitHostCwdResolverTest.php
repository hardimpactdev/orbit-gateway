<?php

declare(strict_types=1);

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Workspace;
use App\Services\Runtime\OrbitHostCwdResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function appAtPath(string $name, string $path): App
{
    $node = createTestAppHostNode(['name' => "app-{$name}", 'tld' => 'test']);

    return App::factory()->for($node, 'node')->create([
        'name' => $name,
        'path' => $path,
        'runtime_kind' => AppRuntimeKind::Php,
    ]);
}

function workspaceAtPath(App $app, string $name, string $path): Workspace
{
    return Workspace::factory()->for($app, 'app')->create([
        'name' => $name,
        'path' => $path,
    ]);
}

describe('OrbitHostCwdResolver', function (): void {
    it('returns null when host cwd is null', function (): void {
        appAtPath('docs', '/home/orbit/apps/docs');

        expect((new OrbitHostCwdResolver)->resolve(null))->toBeNull();
    });

    it('returns null when host cwd is empty', function (): void {
        appAtPath('docs', '/home/orbit/apps/docs');

        expect((new OrbitHostCwdResolver)->resolve(''))->toBeNull();
    });

    it('returns null when no app or workspace matches the host cwd', function (): void {
        appAtPath('docs', '/home/orbit/apps/docs');

        expect((new OrbitHostCwdResolver)->resolve('/tmp/somewhere-else'))->toBeNull();
    });

    it('resolves an app when host cwd is the exact app source path', function (): void {
        $app = appAtPath('docs', '/home/orbit/apps/docs');

        $result = (new OrbitHostCwdResolver)->resolve('/home/orbit/apps/docs');

        expect($result)->not->toBeNull()
            ->and($result->app->is($app))->toBeTrue()
            ->and($result->workspace)->toBeNull();
    });

    it('resolves an app when host cwd is a subdirectory of the app source path', function (): void {
        $app = appAtPath('docs', '/home/orbit/apps/docs');

        $result = (new OrbitHostCwdResolver)->resolve('/home/orbit/apps/docs/app/Http/Controllers');

        expect($result)->not->toBeNull()
            ->and($result->app->is($app))->toBeTrue()
            ->and($result->workspace)->toBeNull();
    });

    it('returns the longest matching app when two app paths could match', function (): void {
        appAtPath('parent', '/home/orbit/apps');
        $child = appAtPath('docs', '/home/orbit/apps/docs');

        $result = (new OrbitHostCwdResolver)->resolve('/home/orbit/apps/docs/public');

        expect($result)->not->toBeNull()
            ->and($result->app->is($child))->toBeTrue();
    });

    it('resolves the workspace when host cwd is inside a workspace source path', function (): void {
        $app = appAtPath('docs', '/home/orbit/apps/docs');
        $workspace = workspaceAtPath($app, 'docs-feature', '/home/orbit/apps/docs/.worktrees/docs-feature');

        $result = (new OrbitHostCwdResolver)->resolve('/home/orbit/apps/docs/.worktrees/docs-feature/app');

        expect($result)->not->toBeNull()
            ->and($result->app->is($app))->toBeTrue()
            ->and($result->workspace)->not->toBeNull()
            ->and($result->workspace->is($workspace))->toBeTrue();
    });

    it('prefers the workspace over the parent app when both could match the host cwd', function (): void {
        $app = appAtPath('docs', '/home/orbit/apps/docs');
        $workspace = workspaceAtPath($app, 'docs-feature', '/home/orbit/apps/docs/.worktrees/docs-feature');

        // Caller is exactly at the workspace path.
        $result = (new OrbitHostCwdResolver)->resolve('/home/orbit/apps/docs/.worktrees/docs-feature');

        expect($result->workspace)->not->toBeNull()
            ->and($result->workspace->is($workspace))->toBeTrue()
            ->and($result->app->is($app))->toBeTrue();
    });

    it('returns the parent app without a workspace when host cwd is in the app source but outside any workspace', function (): void {
        $app = appAtPath('docs', '/home/orbit/apps/docs');
        workspaceAtPath($app, 'docs-feature', '/home/orbit/apps/docs/.worktrees/docs-feature');

        $result = (new OrbitHostCwdResolver)->resolve('/home/orbit/apps/docs/app/Models');

        expect($result)->not->toBeNull()
            ->and($result->app->is($app))->toBeTrue()
            ->and($result->workspace)->toBeNull();
    });

    it('does not match an app path that is a string prefix but not a directory boundary', function (): void {
        appAtPath('docs', '/home/orbit/apps/docs');

        // /home/orbit/apps/docs-other is NOT inside /home/orbit/apps/docs.
        expect((new OrbitHostCwdResolver)->resolve('/home/orbit/apps/docs-other'))->toBeNull();
    });

    it('rejects a host cwd that escapes the app path via parent-directory segments', function (): void {
        appAtPath('docs', '/home/orbit/apps/docs');

        // Lexical resolution collapses /apps/docs/../outside to /apps/outside,
        // which does not match /apps/docs even though the raw string prefix
        // would.
        expect((new OrbitHostCwdResolver)->resolve('/home/orbit/apps/docs/../outside'))->toBeNull();
    });

    it('matches an app path through single-dot segments because they are no-ops', function (): void {
        $app = appAtPath('docs', '/home/orbit/apps/docs');

        $result = (new OrbitHostCwdResolver)->resolve('/home/orbit/apps/docs/./sub');

        expect($result)->not->toBeNull()
            ->and($result->app->is($app))->toBeTrue();
    });

    it('does not match a sibling app that shares a prefix when `..` traversal is involved', function (): void {
        appAtPath('docs', '/home/orbit/apps/docs');
        $sibling = appAtPath('docs2', '/home/orbit/apps/docs2');

        // /apps/docs/../docs2 collapses to /apps/docs2; it must resolve to
        // the sibling app, not the original docs app whose path is the
        // string prefix.
        $result = (new OrbitHostCwdResolver)->resolve('/home/orbit/apps/docs/../docs2');

        expect($result)->not->toBeNull()
            ->and($result->app->is($sibling))->toBeTrue();
    });

    it('returns null for a relative host cwd that cannot be resolved without filesystem access', function (): void {
        appAtPath('docs', '/home/orbit/apps/docs');

        expect((new OrbitHostCwdResolver)->resolve('relative/path'))->toBeNull();
    });
});
