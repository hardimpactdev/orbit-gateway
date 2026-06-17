<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\OrbitUpdater;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

it('runs migrations inside orbit-gateway', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app(OrbitUpdater::class)->runMigrations();

    Process::assertRan(fn ($process): bool => is_array($process->command)
        && $process->command[0] === 'docker'
        && $process->command[1] === 'exec'
        && $process->command[2] === 'orbit-gateway'
        && $process->command[3] === 'php'
        && $process->command[4] === 'apps/gateway/artisan'
        && $process->command[5] === 'migrate'
        && $process->command[6] === '--force');
});

it('installs dependencies inside orbit-gateway', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app(OrbitUpdater::class)->installDependencies();

    Process::assertRan(fn ($process): bool => is_array($process->command)
        && $process->command[0] === 'docker'
        && $process->command[1] === 'exec'
        && $process->command[2] === 'orbit-gateway'
        && $process->command[3] === 'composer'
        && $process->command[4] === '--working-dir=apps/gateway'
        && $process->command[5] === 'install'
        && $process->command[6] === '--no-interaction');
});

it('updates remote nodes through orbit-gateway container', function (): void {
    $node = new Node([
        'name' => 'beast',
        'orbit_path' => '/home/nckrtl/orbit',
    ]);
    $shell = new OrbitUpdaterTestRemoteShell;
    app()->instance(RemoteShell::class, $shell);

    $result = app(OrbitUpdater::class)->updateRemote($node);

    expect($result->successful())->toBeTrue();
    expect(array_column($shell->calls, 'script'))->toBe([
        'git pull --ff-only',
        'docker exec orbit-gateway composer --working-dir=apps/gateway install --no-interaction',
        'docker exec orbit-gateway php apps/gateway/artisan migrate --force',
    ]);
    expect(array_column($shell->calls, 'cwd'))->toBe([
        '/home/nckrtl/orbit',
        '/home/nckrtl/orbit',
        '/home/nckrtl/orbit',
    ]);
});

final class OrbitUpdaterTestRemoteShell implements RemoteShell
{
    /**
     * @var list<array{node: string, script: string, cwd: string|null, timeout: int|null}>
     */
    public array $calls = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node->name,
            'script' => $script,
            'cwd' => $options['cwd'] ?? null,
            'timeout' => $options['timeout'] ?? null,
        ];

        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
