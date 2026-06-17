<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Enums\ProcessEventType;
use App\Http\Gateway\GatewayApiException;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeDrivers\ProcessRuntimeDriver;
use Illuminate\Database\Eloquent\Collection;

final readonly class StopProcesses
{
    public function __construct(
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private RecordProcessEvent $recordProcessEvent,
    ) {}

    /**
     * @return array{data: array<string, mixed>, failed: bool, meta: array<string, mixed>, message: string}
     */
    public function handle(ProcessOwnerContext $context, ?string $name): array
    {
        $processes = $context->lifecycleProcesses($name);

        if ($processes->isEmpty()) {
            if ($name !== null) {
                throw new GatewayApiException("Process '{$name}' not found for {$context->label()}.", 'process.not_found', $context->errorMeta($name));
            }

            throw new GatewayApiException("{$context->label()} has no configured processes.", 'process.none_configured', $context->errorMeta());
        }

        $runtimes = [];
        $failed = false;
        $stopped = 0;

        foreach ($this->runtimeTargets($context, $processes) as $target) {
            $process = $target['process'];
            $runtimeUnit = $target['runtime_unit'];
            $workspace = $context->runtimeWorkspaceFor($process);
            $ok = $target['driver']->stop($context->node, $runtimeUnit);
            $event = null;

            if ($ok) {
                $event = $this->recordProcessEvent->handle(ProcessEventType::Stopped, $context->eventApp(), $workspace, $process, $context->node, $runtimeUnit);
                $stopped++;
            }

            $failed = $failed || ! $ok;
            $runtimes[] = [
                'process' => $process->name,
                'node' => $context->node->name,
                'app' => $context->app?->name,
                'workspace' => $workspace?->name,
                'runtime_unit' => $runtimeUnit,
                'state' => $ok ? 'stopped' : 'failed',
                'event' => $event === null ? null : [
                    'id' => $event->id,
                    'type' => $event->event->value,
                ],
                ...($ok ? [] : ['message' => 'The runtime backend reported a stop failure.']),
            ];
        }

        return [
            'data' => ['runtimes' => $runtimes],
            'failed' => $failed,
            'message' => 'The runtime unit could not be stopped.',
            'meta' => [
                'process' => $name,
                'partial_state' => $stopped === 0 ? 'none_stopped' : 'partially_stopped',
            ],
        ];
    }

    /**
     * @param  Collection<int, Process>  $processes
     * @return list<array{process: Process, driver: ProcessRuntimeDriver, runtime_unit: string}>
     */
    private function runtimeTargets(ProcessOwnerContext $context, Collection $processes): array
    {
        $app = $context->runtimeApp();

        return $processes
            ->map(function (Process $process) use ($context, $app): array {
                $driver = $this->runtimeDrivers->forProcess($process);
                $workspace = $context->runtimeWorkspaceFor($process);

                return [
                    'process' => $process,
                    'driver' => $driver,
                    'runtime_unit' => $driver->runtimeUnitName($app, $process, $workspace),
                ];
            })
            ->values()
            ->all();
    }
}
