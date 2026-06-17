<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeDrivers\DockerProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\DockerSwarmProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\SystemdProcessRuntimeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves concrete drivers by process runtime', function (): void {
    $registry = app(ProcessRuntimeDriverRegistry::class);

    expect($registry->for(ProcessRuntime::Docker))
        ->toBeInstanceOf(DockerProcessRuntimeDriver::class)
        ->and($registry->for(ProcessRuntime::DockerSwarm))
        ->toBeInstanceOf(DockerSwarmProcessRuntimeDriver::class)
        ->and($registry->for(ProcessRuntime::Systemd))
        ->toBeInstanceOf(SystemdProcessRuntimeDriver::class);
});
