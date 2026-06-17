<?php

declare(strict_types=1);

use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EReachability;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function e2eReachabilityResult(bool $successful = true, string $output = '', string $errorOutput = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}

it('requires dig before resolving DNS over WireGuard', function (): void {
    $command = null;
    $instance = m::mock(E2EInstance::class);
    $key = new SshKeyPair('/tmp/private', '/tmp/public');

    $instance->shouldReceive('ssh')
        ->once()
        ->withArgs(function (string $user, SshKeyPair $sshKey, string $sshCommand, ?int $timeoutSeconds) use ($key, &$command): bool {
            $command = $sshCommand;

            return $user === 'orbit'
                && $sshKey === $key
                && $timeoutSeconds === 120;
        })
        ->andReturn(e2eReachabilityResult(output: "10.6.0.2\n"));

    E2EReachability::assertDnsResolvesOverWg(
        operator: $instance,
        operatorUser: 'orbit',
        key: $key,
        hostname: 'gateway-1.gateway',
        expectedIp: '10.6.0.2',
    );

    expect($command)->toContain('command -v dig')
        ->and($command)->not->toContain('apt-get')
        ->and($command)->toContain("dig +time=15 +short 'gateway-1.gateway' @'10.6.0.1'");
});

it('resolves URL hostnames through gateway DNS before curl reachability checks', function (): void {
    $command = null;
    $instance = m::mock(E2EInstance::class);
    $key = new SshKeyPair('/tmp/private', '/tmp/public');

    $instance->shouldReceive('ssh')
        ->once()
        ->withArgs(function (string $user, SshKeyPair $sshKey, string $sshCommand, ?int $timeoutSeconds) use ($key, &$command): bool {
            $command = $sshCommand;

            return $user === 'orbit'
                && $sshKey === $key
                && $timeoutSeconds === 120;
        })
        ->andReturn(e2eReachabilityResult(output: '200'));

    E2EReachability::assertHttpReachable(
        operator: $instance,
        operatorUser: 'orbit',
        key: $key,
        url: 'https://gateway-1.gateway/',
    );

    expect($command)->toContain("dig +time=15 +short 'gateway-1.gateway' @'10.6.0.1'")
        ->and($command)->toContain('--resolve')
        ->and($command)->toContain("'gateway-1.gateway:443:'\"\$resolved_ip\"")
        ->and($command)->toContain('curl -k -s -o /dev/null -w "%{http_code}" --max-time 15')
        ->and($command)->toContain("'https://gateway-1.gateway/'");
});

it('allows removed apps to become unreachable instead of returning an HTTP status', function (): void {
    $command = null;
    $instance = m::mock(E2EInstance::class);
    $key = new SshKeyPair('/tmp/private', '/tmp/public');

    $instance->shouldReceive('ssh')
        ->once()
        ->withArgs(function (string $user, SshKeyPair $sshKey, string $sshCommand, ?int $timeoutSeconds) use ($key, &$command): bool {
            $command = $sshCommand;

            return $user === 'orbit'
                && $sshKey === $key
                && $timeoutSeconds === 120;
        })
        ->andReturn(e2eReachabilityResult(output: '000'));

    E2EReachability::assertHttpNotServing(
        operator: $instance,
        operatorUser: 'orbit',
        key: $key,
        url: 'https://docs.test/',
    );

    expect($command)->toContain(' || true')
        ->and($command)->toContain("'docs.test:443:'\"\$resolved_ip\"");
});
