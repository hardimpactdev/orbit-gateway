<?php

declare(strict_types=1);

use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\RemoteShell\RemoteOrbitGatewayExecutor;
use App\Services\Runtime\OrbitGatewayContainer;
use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('wraps plain commands in docker exec on orbit-gateway', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "installed\n"),
    ]);

    $result = app(RemoteOrbitGatewayExecutor::class)->run(remoteRuntimeExecutorNode(), 'composer install --no-interaction');

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toBe("installed\n");

    Process::assertRan(fn (PendingProcess $process): bool => str_contains(
        (string) $process->command,
        'docker exec -i orbit-gateway composer install --no-interaction',
    ));
});

it('normalizes artisan commands to the gateway artisan path inside orbit-gateway', function (string $script): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "migrated\n"),
    ]);

    app(RemoteOrbitGatewayExecutor::class)->run(remoteRuntimeExecutorNode(), $script);

    Process::assertRan(fn (PendingProcess $process): bool => str_contains(
        (string) $process->command,
        'docker exec -i orbit-gateway php apps/gateway/artisan migrate --force',
    ));
})->with([
    'artisan migrate --force',
    'php artisan migrate --force',
]);

it('unwraps docker exec variants that already target orbit-gateway', function (string $script, array $expectedFragments): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "migrated\n"),
    ]);

    app(RemoteOrbitGatewayExecutor::class)->run(remoteRuntimeExecutorNode(), $script);

    Process::assertRan(function (PendingProcess $process) use ($expectedFragments): bool {
        $command = (string) $process->command;
        $containsExpectedFragments = array_all(
            $expectedFragments,
            fn (string $fragment): bool => str_contains($command, $fragment),
        );

        return $containsExpectedFragments
            && substr_count($command, ' orbit-gateway ') === 1
            && ! str_contains($command, 'orbit-gateway docker exec')
            && str_contains($command, 'orbit-gateway php apps/gateway/artisan migrate --force');
    });
})->with([
    'no options' => [
        'docker exec orbit-gateway php apps/gateway/artisan migrate --force',
        [],
    ],
    'short interactive' => [
        'docker exec -i orbit-gateway php apps/gateway/artisan migrate --force',
        [],
    ],
    'long interactive' => [
        'docker exec --interactive orbit-gateway php apps/gateway/artisan migrate --force',
        [],
    ],
    'tty' => [
        'docker exec -t orbit-gateway php apps/gateway/artisan migrate --force',
        ['--tty'],
    ],
    'combined interactive tty' => [
        'docker exec -it orbit-gateway php apps/gateway/artisan migrate --force',
        ['--tty'],
    ],
    'user before container' => [
        'docker exec -i --user orbit orbit-gateway php apps/gateway/artisan migrate --force',
        ['--user', 'orbit'],
    ],
    'workdir before container' => [
        'docker exec -i --workdir /opt/orbit orbit-gateway php apps/gateway/artisan migrate --force',
        ['--workdir', '/opt/orbit'],
    ],
    'env before container' => [
        'docker exec -e KEY=val orbit-gateway php apps/gateway/artisan migrate --force',
        ['--env', 'KEY=val'],
    ],
    'equals user syntax' => [
        'docker exec --user=orbit orbit-gateway php apps/gateway/artisan migrate --force',
        ['--user', 'orbit'],
    ],
    'short user syntax' => [
        'docker exec -u orbit orbit-gateway php apps/gateway/artisan migrate --force',
        ['--user', 'orbit'],
    ],
    'equals workdir syntax' => [
        'docker exec --workdir=/opt/orbit orbit-gateway php apps/gateway/artisan migrate --force',
        ['--workdir', '/opt/orbit'],
    ],
    'short workdir syntax' => [
        'docker exec -w /opt/orbit orbit-gateway php apps/gateway/artisan migrate --force',
        ['--workdir', '/opt/orbit'],
    ],
    'equals env syntax' => [
        'docker exec --env=KEY=val orbit-gateway php apps/gateway/artisan migrate --force',
        ['--env', 'KEY=val'],
    ],
    'attached short env syntax' => [
        'docker exec -eKEY=val orbit-gateway php apps/gateway/artisan migrate --force',
        ['--env', 'KEY=val'],
    ],
    'env file before container' => [
        'docker exec --env-file /tmp/runtime.env orbit-gateway php apps/gateway/artisan migrate --force',
        ['--env-file', '/tmp/runtime.env'],
    ],
    'privileged before container' => [
        'docker exec --privileged orbit-gateway php apps/gateway/artisan migrate --force',
        ['--privileged'],
    ],
    'detach keys before container' => [
        'docker exec --detach-keys ctrl-p,ctrl-q orbit-gateway php apps/gateway/artisan migrate --force',
        ['--detach-keys', 'ctrl-p,ctrl-q'],
    ],
    'detach before container' => [
        'docker exec --detach orbit-gateway php apps/gateway/artisan migrate --force',
        ['--detach'],
    ],
    'whitespace padded' => [
        '  docker   exec   -i   orbit-gateway   php   apps/gateway/artisan   migrate   --force  ',
        [],
    ],
]);

it('merges unwrapped docker exec workdir with runtime cwd without conflicts', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "migrated\n"),
    ]);
    $node = remoteRuntimeExecutorNode();

    app(RemoteOrbitGatewayExecutor::class)->run(
        $node,
        'docker exec -i --workdir /opt/orbit orbit-gateway php apps/gateway/artisan migrate --force',
        ['cwd' => $node->orbit_path],
    );

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (string) $process->command;

        return substr_count($command, '--workdir') === 1
            && str_contains($command, '/opt/orbit')
            && ! str_contains($command, '/home/orbit/orbit')
            && ! str_contains($command, 'orbit-gateway docker exec');
    });
});

it('lets runtime cwd override unwrapped docker exec workdir for shell fallback', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "compound\n"),
    ]);
    $node = remoteRuntimeExecutorNode();

    app(RemoteOrbitGatewayExecutor::class)->run(
        $node,
        "docker exec -i --workdir /foo orbit-gateway sh -c 'echo hi && echo bye'",
        ['cwd' => $node->orbit_path],
    );

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (string) $process->command;

        return substr_count($command, '--workdir') === 1
            && str_contains($command, OrbitGatewayContainer::SourcePath)
            && ! str_contains($command, '/foo')
            && ! str_contains($command, 'cd ')
            && str_contains($command, 'echo hi && echo bye');
    });
});

it('merges unwrapped docker exec env with runtime metadata deterministically', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "migrated\n"),
    ]);

    app(RemoteOrbitGatewayExecutor::class)->run(
        remoteRuntimeExecutorNode(),
        'docker exec -e CALLER_KEY=caller -e ORBIT_REQUEST_ID=caller orbit-gateway php apps/gateway/artisan migrate --force',
        ['metadata' => ['ORBIT_REQUEST_ID' => 'runtime-req']],
    );

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (string) $process->command;

        return substr_count($command, '--env') === 2
            && str_contains($command, 'CALLER_KEY=caller')
            && str_contains($command, 'ORBIT_REQUEST_ID=runtime-req')
            && ! str_contains($command, 'ORBIT_REQUEST_ID=caller')
            && ! str_contains($command, 'orbit-gateway docker exec');
    });
});

it('does not unwrap docker exec commands for other containers', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "container\n"),
    ]);

    app(RemoteOrbitGatewayExecutor::class)->run(
        remoteRuntimeExecutorNode(),
        'docker exec -i other-container php artisan migrate --force',
    );

    Process::assertRan(fn (PendingProcess $process): bool => str_contains(
        (string) $process->command,
        'orbit-gateway docker exec -i other-container php artisan migrate --force',
    ));
});

it('only unwraps docker exec when it starts the command', function (string $script, string $expectedFragment): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "ignored\n"),
    ]);

    app(RemoteOrbitGatewayExecutor::class)->run(remoteRuntimeExecutorNode(), $script);

    Process::assertRan(fn (PendingProcess $process): bool => str_contains((string) $process->command, $expectedFragment));
})->with([
    'substring' => [
        'printf %s docker exec orbit-gateway php artisan migrate --force',
        'orbit-gateway printf %s docker exec orbit-gateway php artisan migrate --force',
    ],
    'middle of string' => [
        'echo before; docker exec orbit-gateway php artisan migrate --force',
        'sh -c',
    ],
]);

it('preserves runtime env, cwd, timeout, input, stdout, and stderr semantics', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "runtime-ok\n", errorOutput: "runtime-warning\n"),
    ]);
    $node = remoteRuntimeExecutorNode();

    $result = app(RemoteOrbitGatewayExecutor::class)->run($node, 'php artisan orbit:cleanup', [
        'cwd' => $node->orbit_path,
        'metadata' => ['ORBIT_REQUEST_ID' => 'runtime-req'],
        'timeout' => 75,
        'input' => 'runtime-stdin',
    ]);

    expect($result->successful())->toBeTrue()
        ->and($result->stdout)->toBe("runtime-ok\n")
        ->and($result->stderr)->toBe("runtime-warning\n");

    Process::assertRan(function (PendingProcess $process, ProcessResultContract $processResult): bool {
        $command = (string) $process->command;

        return str_contains($command, 'docker exec -i')
            && str_contains($command, '--env')
            && str_contains($command, 'ORBIT_REQUEST_ID=runtime-req')
            && str_contains($command, '--workdir')
            && str_contains($command, OrbitGatewayContainer::SourcePath)
            && ! str_contains($command, '/home/orbit/orbit')
            && str_contains($command, 'orbit-gateway php apps/gateway/artisan orbit:cleanup')
            && $process->timeout === 75
            && $process->input === 'runtime-stdin'
            && $processResult->output() === "runtime-ok\n"
            && $processResult->errorOutput() === "runtime-warning\n";
    });
});

it('falls back to an in-container shell for compound runtime scripts', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "compound\n"),
    ]);

    app(RemoteOrbitGatewayExecutor::class)->run(
        remoteRuntimeExecutorNode(),
        'php apps/gateway/artisan migrate --force && php apps/gateway/artisan orbit:cleanup',
        ['metadata' => ['ORBIT_REQUEST_ID' => 'runtime-compound']],
    );

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (string) $process->command;

        return str_contains($command, 'docker exec -i')
            && str_contains($command, 'orbit-gateway sh -c')
            && ! str_contains($command, 'docker exec -i orbit-gateway sh -lc')
            && str_contains($command, '--env')
            && str_contains($command, 'ORBIT_REQUEST_ID')
            && str_contains($command, 'php apps/gateway/artisan migrate --force && php apps/gateway/artisan orbit:cleanup');
    });
});

it('throws runtime shell failures with the same RemoteShellFailed semantics', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(errorOutput: "runtime denied\n", exitCode: 13),
    ]);

    try {
        app(RemoteOrbitGatewayExecutor::class)->run(remoteRuntimeExecutorNode(['name' => 'runtime-failure']), 'php artisan migrate --force', [
            'throw' => true,
        ]);

        $this->fail('Expected the runtime executor to throw a remote shell failure.');
    } catch (RemoteShellFailed $exception) {
        expect($exception->node->name)->toBe('runtime-failure')
            ->and($exception->script)->toBe('docker exec -i orbit-gateway php apps/gateway/artisan migrate --force')
            ->and($exception->result->exitCode)->toBe(13)
            ->and($exception->getMessage())->toContain('RemoteShell failed on runtime-failure (exit 13): runtime denied');
    }
});

/**
 * @param  array<string, mixed>  $attributes
 */
function remoteRuntimeExecutorNode(array $attributes = []): Node
{
    return Node::factory()->create([
        'name' => 'runtime-node',
        'host' => 'runtime-node.example.com',
        'wireguard_address' => '10.44.0.60',
        'user' => 'orbit',
        ...remoteRuntimeExecutorPinnedHostKey(),
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function remoteRuntimeExecutorPinnedHostKey(): array
{
    return [
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIRemoteRuntimeExecutorPinnedKey',
        'host_key_fingerprint' => 'SHA256:remote-runtime-executor',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ];
}
