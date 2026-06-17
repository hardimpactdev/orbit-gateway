<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceStep;
use App\Services\Workspaces\WorkspaceSetupStepRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    DB::table('nodes')->insert([
        'name' => 'app-1',
        'host' => 'app-1',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('executes setup steps sequentially on the host by default', function (): void {
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerTestShell;

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'echo first',
            'timeout_seconds' => 60,
        ]),
        new WorkspaceStep([
            'id' => 2,
            'command' => 'echo second',
            'timeout_seconds' => 60,
        ]),
    ];

    $result = $runner->run($run, $steps, '/app/path', ['ORBIT_APP' => 'demo'], $node);

    expect($result)->toBeTrue()
        ->and($shell->runs)->toHaveCount(2)
        ->and($shell->runs[0]['script'])->toBe('echo first')
        ->and($shell->runs[0]['options']['cwd'])->toBe('/app/path')
        ->and($shell->runs[1]['script'])->toBe('echo second')
        ->and($shell->runs[1]['options']['cwd'])->toBe('/app/path');

    $run->refresh();
    expect($run->status)->toBe('completed');
});

it('routes php and composer commands through the workspace container when given a container name', function (): void {
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerTestShell;

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'composer install',
            'timeout_seconds' => 120,
        ]),
        new WorkspaceStep([
            'id' => 2,
            'command' => 'php artisan migrate',
            'timeout_seconds' => 60,
        ]),
        new WorkspaceStep([
            'id' => 3,
            'command' => 'npm ci',
            'timeout_seconds' => 300,
        ]),
    ];

    $env = ['ORBIT_APP' => 'demo', 'ORBIT_WORKSPACE_NAME' => 'feature'];
    $result = $runner->run($run, $steps, '/app/path', $env, $node, 'orbit-ws-demo-feature');

    expect($result)->toBeTrue();

    $composerRun = $shell->runs[0];
    expect($composerRun['script'])
        ->toContain("'docker'")
        ->toContain("'exec'")
        ->toContain("'orbit-ws-demo-feature'")
        ->toContain("'composer install'")
        ->toContain("'-w'")
        ->toContain("'/app'");
    expect($composerRun['options']['cwd'] ?? null)->toBeNull();

    $artisanRun = $shell->runs[1];
    expect($artisanRun['script'])
        ->toContain("'docker'")
        ->toContain("'exec'")
        ->toContain("'orbit-ws-demo-feature'")
        ->toContain("'php artisan migrate'")
        ->toContain("'-w'")
        ->toContain("'/app'");
    expect($artisanRun['options']['cwd'] ?? null)->toBeNull();

    $npmRun = $shell->runs[2];
    expect($npmRun['script'])->toBe('npm ci');
    expect($npmRun['options']['cwd'])->toBe('/app/path');
});

it('passes lifecycle environment into containerized commands via docker exec -e', function (): void {
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerTestShell;

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'composer install',
            'timeout_seconds' => 120,
        ]),
    ];

    $env = ['ORBIT_APP' => 'demo', 'VITE_APP_URL' => 'https://feature.demo.test'];
    $runner->run($run, $steps, '/app/path', $env, $node, 'orbit-ws-demo-feature');

    expect($shell->runs[0]['script'])
        ->toContain("'ORBIT_APP=demo'")
        ->toContain("'VITE_APP_URL=https://feature.demo.test'");
});

it('fails fast on first non-zero exit and records the failed step', function (): void {
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerFailingShell(failAfter: 0);

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'exit 1',
            'timeout_seconds' => 60,
        ]),
        new WorkspaceStep([
            'id' => 2,
            'command' => 'echo second',
            'timeout_seconds' => 60,
        ]),
    ];

    $result = $runner->run($run, $steps, '/app/path', [], $node);

    expect($result)->toBeFalse()
        ->and($shell->runs)->toHaveCount(1);

    $run->refresh();
    expect($run->status)->toBe('failed');

    $failedStep = $run->runSteps()->first();
    expect($failedStep)->not->toBeNull()
        ->and($failedStep->exit_code)->toBe(1);
});

it('reports progress events for each step', function (): void {
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerTestShell;

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'echo first',
            'timeout_seconds' => 60,
        ]),
    ];

    $events = [];
    $runner->run($run, $steps, '/app/path', [], $node, null, function (string $event, WorkspaceStep $step, int $index, int $count) use (&$events): void {
        $events[] = [$event, $step->command, $index, $count];
    });

    expect($events)->toBe([
        ['running', 'echo first', 1, 1],
        ['completed', 'echo first', 1, 1],
    ]);
});

it('reports failed progress event when a step fails', function (): void {
    $run = WorkspaceRun::factory()->create(['status' => 'pending']);
    $node = Node::query()->firstOrFail();
    $shell = new WorkspaceSetupStepRunnerFailingShell(failAfter: 0);

    $runner = new WorkspaceSetupStepRunner($shell);

    $steps = [
        new WorkspaceStep([
            'id' => 1,
            'command' => 'exit 1',
            'timeout_seconds' => 60,
        ]),
    ];

    $events = [];
    $runner->run($run, $steps, '/app/path', [], $node, null, function (string $event, WorkspaceStep $step, int $index, int $count) use (&$events): void {
        $events[] = [$event, $step->command, $index, $count];
    });

    expect($events)->toBe([
        ['running', 'exit 1', 1, 1],
        ['failed', 'exit 1', 1, 1],
    ]);
});

final class WorkspaceSetupStepRunnerTestShell implements RemoteShell
{
    /** @var list<array{script: string, options: array<string, mixed>}> */
    public array $runs = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = ['script' => $script, 'options' => $options];

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class WorkspaceSetupStepRunnerFailingShell implements RemoteShell
{
    public int $callCount = 0;

    /** @var list<array{script: string, options: array<string, mixed>}> */
    public array $runs = [];

    public function __construct(private int $failAfter) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->callCount++;
        $this->runs[] = ['script' => $script, 'options' => $options];

        if ($this->callCount > $this->failAfter) {
            return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
