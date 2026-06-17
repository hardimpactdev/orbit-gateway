<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\StartsRemoteShellProcesses;
use App\Data\RemoteShell\RemoteShellPoolJob;
use App\Data\RemoteShell\RemoteShellPoolResult;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\RemoteShell\RemoteShellPool;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('falls back to synchronous remote shell runs when async start is unavailable', function (): void {
    $first = Node::factory()->create(['name' => 'app-1']);
    $second = Node::factory()->create(['name' => 'app-2']);
    $shell = new RemoteShellPoolSynchronousShell;

    $results = (new RemoteShellPool($shell))->run([
        new RemoteShellPoolJob(key: 'first', node: $first, script: 'echo first', options: ['cwd' => '/srv/one']),
        new RemoteShellPoolJob(key: 'second', node: $second, script: 'echo second', options: ['timeout' => 45]),
    ], concurrency: 4);

    expect(array_map(fn (RemoteShellPoolResult $result): string => $result->key, $results))->toBe(['first', 'second'])
        ->and($results[0]->result?->stdout)->toBe("app-1:echo first\n")
        ->and($results[1]->result?->stdout)->toBe("app-2:echo second\n")
        ->and($results[0]->exception)->toBeNull()
        ->and($shell->calls)->toBe([
            ['node' => 'app-1', 'script' => 'echo first', 'options' => ['cwd' => '/srv/one']],
            ['node' => 'app-2', 'script' => 'echo second', 'options' => ['timeout' => 45]],
        ]);
});

it('runs remote shell jobs concurrently through async process starts', function (): void {
    $nodes = [
        Node::factory()->create(['name' => 'app-1']),
        Node::factory()->create(['name' => 'app-2']),
        Node::factory()->create(['name' => 'app-3']),
    ];
    $shell = new RemoteShellPoolAsyncShell;

    $results = (new RemoteShellPool($shell))->run([
        new RemoteShellPoolJob(key: 'one', node: $nodes[0], script: 'echo one'),
        new RemoteShellPoolJob(key: 'two', node: $nodes[1], script: 'echo two'),
        new RemoteShellPoolJob(key: 'three', node: $nodes[2], script: 'echo three'),
    ], concurrency: 2);

    expect(array_map(fn (RemoteShellPoolResult $result): string => $result->key, $results))->toBe(['one', 'two', 'three'])
        ->and(array_map(fn (RemoteShellPoolResult $result): ?string => $result->result?->stdout, $results))->toBe([
            "app-1:echo one\n",
            "app-2:echo two\n",
            "app-3:echo three\n",
        ])
        ->and($shell->maxActiveProcesses)->toBe(2)
        ->and($shell->runCalls)->toBe(0);
});

it('captures start exceptions and continues running later jobs', function (): void {
    $nodes = [
        Node::factory()->create(['name' => 'app-1']),
        Node::factory()->create(['name' => 'app-2']),
    ];
    $shell = new RemoteShellPoolAsyncShell(failToStartFor: ['app-1']);

    $results = (new RemoteShellPool($shell))->run([
        new RemoteShellPoolJob(key: 'broken', node: $nodes[0], script: 'echo broken'),
        new RemoteShellPoolJob(key: 'ok', node: $nodes[1], script: 'echo ok'),
    ], concurrency: 2);

    expect($results)->toHaveCount(2)
        ->and($results[0]->key)->toBe('broken')
        ->and($results[0]->result)->toBeNull()
        ->and($results[0]->exception?->getMessage())->toBe('could not start app-1')
        ->and($results[1]->key)->toBe('ok')
        ->and($results[1]->result?->successful())->toBeTrue()
        ->and($results[1]->result?->stdout)->toBe("app-2:echo ok\n");
});

it('preserves throw option semantics for failed async results', function (): void {
    $node = Node::factory()->create(['name' => 'app-1']);
    $shell = new RemoteShellPoolAsyncShell(
        exitCodes: ['app-1' => 13],
        errorOutputs: ['app-1' => 'permission denied'],
    );

    $results = (new RemoteShellPool($shell))->run([
        new RemoteShellPoolJob(
            key: 'failed',
            node: $node,
            script: 'mkdir /srv/example',
            options: ['throw' => true],
        ),
    ], concurrency: 2);

    expect($results[0]->result)->toBeNull()
        ->and($results[0]->exception)->toBeInstanceOf(RemoteShellFailed::class)
        ->and($results[0]->exception->getMessage())->toContain('RemoteShell failed on app-1 (exit 13): permission denied');
});

final class RemoteShellPoolSynchronousShell implements RemoteShell
{
    /**
     * @var list<array{node: string, script: string, options: array<string, mixed>}>
     */
    public array $calls = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node->name,
            'script' => $script,
            'options' => $options,
        ];

        return new RemoteShellResult(
            exitCode: 0,
            stdout: "{$node->name}:{$script}\n",
            stderr: '',
            durationMs: 1,
        );
    }
}

final class RemoteShellPoolAsyncShell implements RemoteShell, StartsRemoteShellProcesses
{
    public int $runCalls = 0;

    public int $activeProcesses = 0;

    public int $maxActiveProcesses = 0;

    /**
     * @param  list<string>  $failToStartFor
     * @param  array<string, int>  $exitCodes
     * @param  array<string, string>  $errorOutputs
     */
    public function __construct(
        private readonly array $failToStartFor = [],
        private readonly array $exitCodes = [],
        private readonly array $errorOutputs = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runCalls++;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        if (in_array($node->name, $this->failToStartFor, true)) {
            throw new RuntimeException("could not start {$node->name}");
        }

        $this->activeProcesses++;
        $this->maxActiveProcesses = max($this->maxActiveProcesses, $this->activeProcesses);

        return new RemoteShellPoolTrackingInvokedProcess(
            new FakeInvokedProcess(
                command: $script,
                process: Process::describe()
                    ->output(($this->exitCodes[$node->name] ?? 0) === 0 ? "{$node->name}:{$script}" : '')
                    ->errorOutput($this->errorOutputs[$node->name] ?? '')
                    ->exitCode($this->exitCodes[$node->name] ?? 0),
            ),
            function (): void {
                $this->activeProcesses--;
            },
        );
    }
}

final class RemoteShellPoolTrackingInvokedProcess implements InvokedProcess
{
    private bool $finished = false;

    public function __construct(
        private readonly InvokedProcess $process,
        private readonly Closure $onFinished,
    ) {}

    public function id(): ?int
    {
        return $this->process->id();
    }

    public function command(): string
    {
        return $this->process->command();
    }

    public function signal(int $signal): static
    {
        $this->process->signal($signal);

        return $this;
    }

    public function running(): bool
    {
        return $this->process->running();
    }

    public function output(): string
    {
        return $this->process->output();
    }

    public function errorOutput(): string
    {
        return $this->process->errorOutput();
    }

    public function latestOutput(): string
    {
        return $this->process->latestOutput();
    }

    public function latestErrorOutput(): string
    {
        return $this->process->latestErrorOutput();
    }

    public function wait(?callable $output = null): ProcessResult
    {
        $result = $this->process->wait($output);

        $this->markFinished();

        return $result;
    }

    public function waitUntil(?callable $output = null): ProcessResult
    {
        $result = $this->process->waitUntil($output);

        $this->markFinished();

        return $result;
    }

    private function markFinished(): void
    {
        if ($this->finished) {
            return;
        }

        ($this->onFinished)();
        $this->finished = true;
    }
}
