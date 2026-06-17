<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessRuntimeDrivers\DockerProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\DockerSwarmProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\ProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\SystemdProcessRuntimeDriver;

final readonly class ProcessRuntimeDriverRegistry
{
    public function __construct(
        private DockerProcessRuntimeDriver $docker,
        private DockerSwarmProcessRuntimeDriver $dockerSwarm,
        private SystemdProcessRuntimeDriver $systemd,
    ) {}

    public function for(ProcessRuntime|string $runtime): ProcessRuntimeDriver
    {
        return $this->driverFor($this->resolveRuntime($runtime));
    }

    public function forProcess(Process $process): ProcessRuntimeDriver
    {
        $runtime = $this->resolveRuntime(
            runtime: $process->getAttributes()['runtime'] ?? $process->getRawOriginal('runtime') ?? null,
            process: $process,
        );

        $this->assertRuntimeSupportsProcessOwner($runtime, $process);

        return $this->driverFor($runtime);
    }

    private function driverFor(ProcessRuntime $runtime): ProcessRuntimeDriver
    {
        return match ($runtime) {
            ProcessRuntime::Docker => $this->docker,
            ProcessRuntime::DockerSwarm => $this->dockerSwarm,
            ProcessRuntime::Systemd => $this->systemd,
        };
    }

    private function assertRuntimeSupportsProcessOwner(ProcessRuntime $runtime, Process $process): void
    {
        if (! $runtime->requiresNodeOwner()) {
            return;
        }

        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return;
        }

        throw new GatewayApiException(
            "Process '{$process->name}' uses runtime '{$runtime->value}', which is only valid for node-owned processes.",
            'process.unsupported_runtime',
            [
                'process' => $process->name,
                'runtime' => $runtime->value,
                'allowed' => array_map(fn (ProcessRuntime $runtime): string => $runtime->value, ProcessRuntime::cases()),
                'reason' => $runtime->nodeOwnerViolationReason(),
            ],
        );
    }

    private function resolveRuntime(ProcessRuntime|string|null $runtime, ?Process $process = null): ProcessRuntime
    {
        if ($runtime instanceof ProcessRuntime) {
            return $runtime;
        }

        if (is_string($runtime)) {
            $resolvedRuntime = ProcessRuntime::tryFrom($runtime);

            if ($resolvedRuntime instanceof ProcessRuntime) {
                return $resolvedRuntime;
            }
        }

        throw new GatewayApiException(
            $process instanceof Process
                ? "Process '{$process->name}' uses unsupported runtime '{$runtime}'."
                : "Unsupported process runtime '{$runtime}'.",
            'process.unsupported_runtime',
            [
                ...($process instanceof Process ? ['process' => $process->name] : []),
                'runtime' => $runtime,
                'allowed' => array_map(fn (ProcessRuntime $runtime): string => $runtime->value, ProcessRuntime::cases()),
            ],
        );
    }
}
