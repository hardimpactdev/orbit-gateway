<?php

declare(strict_types=1);

use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ENetwork;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function e2eNetworkResult(bool $successful = true, string $output = '', string $errorOutput = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}

it('ensures the synthetic source WireGuard address exists before adding a peer route', function (): void {
    $command = null;
    $instance = m::mock(E2EInstance::class);

    $instance->shouldReceive('exec')
        ->once()
        ->withArgs(function (string $execCommand, ?int $timeoutSeconds) use (&$command): bool {
            $command = $execCommand;

            return $timeoutSeconds === null;
        })
        ->andReturn(e2eNetworkResult());

    $instance->shouldReceive('name')->andReturn('app-dev-1');

    E2ENetwork::routeWireGuardPeer($instance, '10.6.0.2', '10.231.7.38', '10.6.0.4');

    expect($command)->toContain("grep -Fxq '10.6.0.4'")
        ->and($command)->toContain("ip addr add '10.6.0.4/32' dev \"\$iface\"")
        ->and($command)->toContain("ip route replace '10.6.0.2/32' via '10.231.7.38' dev \"\$iface\" src '10.6.0.4'");
});
