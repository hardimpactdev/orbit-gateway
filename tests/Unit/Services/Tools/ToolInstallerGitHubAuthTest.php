<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

afterEach(function (): void {
    putenv('GH_TOKEN');
    putenv('GITHUB_TOKEN');
});

it('stages GitHub auth for laravel installer repairs without embedding the token in scripts', function (): void {
    putenv('GH_TOKEN=ghp_unit_secret');
    putenv('GITHUB_TOKEN');

    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::AppDevelopment->value,
        'status' => NodeRoleStatus::Active->value,
    ]);

    $shell = new ToolInstallerGitHubAuthRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: "/tmp/orbit-secret.github\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $this->app->instance(RemoteShell::class, $shell);

    $result = app(ToolInstaller::class)->install('laravel-installer', node: 'app-dev-1');

    expect($result)->toMatchArray([
        'name' => 'laravel-installer',
        'node' => 'app-dev-1',
        'state' => 'installed',
    ])
        ->and($shell->options[0]['input'])->toBe(base64_encode('ghp_unit_secret'))
        ->and($shell->scripts[0])->toContain('base64 -d')
        ->and($shell->scripts[1])->toContain("GITHUB_TOKEN_FILE='/tmp/orbit-secret.github'")
        ->and($shell->scripts[1])->toContain('${COMPOSER_HOME}/auth.json')
        ->and($shell->scripts[1])->toContain('gh auth login --hostname github.com --with-token')
        ->and($shell->scripts[2])->toBe("rm -f '/tmp/orbit-secret.github'");

    foreach ($shell->scripts as $script) {
        expect($script)->not->toContain('ghp_unit_secret');
    }
});

it('stages GitHub auth for laravel installer updates without embedding the token in scripts', function (): void {
    putenv('GH_TOKEN=ghp_update_secret');
    putenv('GITHUB_TOKEN');

    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::AppDevelopment->value,
        'status' => NodeRoleStatus::Active->value,
    ]);

    NodeTool::factory()->for($node)->create([
        'name' => 'laravel-installer',
        'expected_state' => 'installed',
        'config' => null,
    ]);

    $shell = new ToolInstallerGitHubAuthRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: "/tmp/orbit-secret.github\n", stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $this->app->instance(RemoteShell::class, $shell);

    $result = app(ToolUpdater::class)->update('laravel-installer', node: 'app-dev-1');

    expect($result)->toMatchArray([
        'name' => 'laravel-installer',
        'node' => 'app-dev-1',
    ])
        ->and($shell->options[0]['input'])->toBe(base64_encode('ghp_update_secret'))
        ->and($shell->scripts[1])->toContain("GITHUB_TOKEN_FILE='/tmp/orbit-secret.github'")
        ->and($shell->scripts[1])->toContain('composer global update laravel/installer')
        ->and($shell->scripts[1])->toContain('gh auth login --hostname github.com --with-token')
        ->and($shell->scripts[2])->toBe("rm -f '/tmp/orbit-secret.github'");

    foreach ($shell->scripts as $script) {
        expect($script)->not->toContain('ghp_update_secret');
    }
});

final class ToolInstallerGitHubAuthRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected call', 1);
    }
}
