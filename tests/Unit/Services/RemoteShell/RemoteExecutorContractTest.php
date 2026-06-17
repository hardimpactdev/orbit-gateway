<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\StartsRemoteShellProcesses;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteHostExecutor;
use App\Services\RemoteShell\RemoteOrbitGatewayExecutor;
use Illuminate\Contracts\Process\InvokedProcess;
use Tests\TestCase;

uses(TestCase::class);

it('mirrors the existing remote shell public surface', function (): void {
    $interface = new ReflectionClass(RemoteExecutor::class);

    expect($interface->isInterface())->toBeTrue()
        ->and($interface->implementsInterface(RemoteShell::class))->toBeTrue()
        ->and($interface->implementsInterface(StartsRemoteShellProcesses::class))->toBeTrue();

    $run = $interface->getMethod('run');
    $start = $interface->getMethod('start');

    expect((string) $run->getReturnType())->toBe(RemoteShellResult::class)
        ->and((string) $start->getReturnType())->toBe(InvokedProcess::class)
        ->and(remoteExecutorParameterTypes($run))->toBe([Node::class, 'string', 'array'])
        ->and(remoteExecutorParameterTypes($start))->toBe([Node::class, 'string', 'array']);
});

it('defaults RemoteExecutor resolution to the host executor while keeping runtime explicit', function (): void {
    app()->forgetInstance(RemoteShell::class);
    app()->forgetInstance(StartsRemoteShellProcesses::class);

    expect(app(RemoteExecutor::class))->toBeInstanceOf(RemoteHostExecutor::class)
        ->and(app(RemoteOrbitGatewayExecutor::class))->toBeInstanceOf(RemoteOrbitGatewayExecutor::class)
        ->and(app(RemoteShell::class))->toBeInstanceOf(RemoteHostExecutor::class)
        ->and(app(StartsRemoteShellProcesses::class))->toBeInstanceOf(RemoteHostExecutor::class);
});

/**
 * @return list<string>
 */
function remoteExecutorParameterTypes(ReflectionMethod $method): array
{
    return array_map(
        fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
        $method->getParameters(),
    );
}
