<?php

declare(strict_types=1);

use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ENodeProbe;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

it('checks for the relocated gateway artisan when probing Orbit installs', function (): void {
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('output')->andReturn('');
    $result->shouldReceive('errorOutput')->andReturn('');

    $instance = m::mock(E2EInstance::class);
    $instance->shouldReceive('exec')
        ->once()
        ->with('test -d /home/orbit/orbit && test -f /home/orbit/orbit/apps/gateway/artisan')
        ->andReturn($result);
    $instance->shouldReceive('exec')
        ->once()
        ->with("sudo -iu orbit bash -lc 'orbit --version --local >/dev/null'")
        ->andReturn($result);

    E2ENodeProbe::assertOrbitInstalled($instance);
});
