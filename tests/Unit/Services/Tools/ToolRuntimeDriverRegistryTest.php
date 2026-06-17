<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeDrivers\DockerProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\DockerSwarmProcessRuntimeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves Docker and Docker Swarm runtime drivers from process rows', function (): void {
    $registry = app(ProcessRuntimeDriverRegistry::class);

    $docker = new Process(['runtime' => ProcessRuntime::Docker]);
    $node = Node::factory()->create();
    $swarm = Process::factory()->forOwner($node)->make([
        'runtime' => ProcessRuntime::DockerSwarm,
    ]);

    expect($registry->forProcess($docker))->toBeInstanceOf(DockerProcessRuntimeDriver::class)
        ->and($registry->forProcess($swarm))->toBeInstanceOf(DockerSwarmProcessRuntimeDriver::class);
});
