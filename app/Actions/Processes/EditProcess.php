<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeUnitPayload;

final readonly class EditProcess
{
    public function __construct(
        private EnsureAppProcessRuntimeUnits $ensureRuntimeUnits,
        private ProcessRuntimeUnitPayload $runtimeUnitPayload,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @param  array{command?: string, restart_policy?: ProcessRestartPolicy, crash_notification?: ProcessCrashNotification, runtime?: ProcessRuntime}  $changes
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function handle(ProcessOwnerContext $context, string $name, array $changes, bool $restart): array
    {
        $app = $context->runtimeApp();
        $app->loadMissing(['node', 'workspaces']);

        $process = $context->ownerProcesses()
            ->where('name', $name)
            ->first();

        if (! $process instanceof Process) {
            throw new GatewayApiException("Process '{$name}' not found for {$context->label()}.", 'process.not_found', $context->errorMeta($name));
        }

        $changed = [];
        $previousRuntime = $process->runtime;

        if (isset($changes['command']) && $process->command !== $changes['command']) {
            $process->command = $changes['command'];
            $changed[] = 'command';
        }

        if (isset($changes['restart_policy']) && $process->restart_policy !== $changes['restart_policy']) {
            $process->restart_policy = $changes['restart_policy'];
            $changed[] = 'restart_policy';
        }

        if (isset($changes['crash_notification']) && $process->crash_notification !== $changes['crash_notification']) {
            $process->crash_notification = $changes['crash_notification'];
            $changed[] = 'crash_notification';
        }

        if (isset($changes['runtime']) && $process->runtime !== $changes['runtime']) {
            $context->assertRuntimeAllowed($changes['runtime']);
            $process->runtime = $changes['runtime'];
            $changed[] = 'runtime';
        }

        $process->save();
        $app->unsetRelation('processes');
        $runtimeUnits = $this->runtimeUnitPayload->forProcess($app, $process, $context->runtimeWorkspaceFor($process));
        $warnings = $context->app !== null && $context->workspace === null
            ? $this->ensureRuntimeUnits->handle($app)
            : $this->applyRuntimeUnits($context, $app, $process, $runtimeUnits, $previousRuntime);

        if ($restart) {
            $warnings = [
                ...$warnings,
                ...$this->restartRuntimeUnits($context, $process, $runtimeUnits),
            ];
        }

        return [
            'data' => [
                'process' => [
                    ...$context->processPayload($process),
                ],
                'changed' => $changed,
                'runtime_units' => $runtimeUnits,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function applyRuntimeUnits(ProcessOwnerContext $context, App $app, Process $process, array $runtimeUnits, ProcessRuntime $previousRuntime): array
    {
        $warnings = [];
        $driver = $this->runtimeDrivers->forProcess($process);

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $workspace = $context->runtimeWorkspaceFor($process);
            $cleanupScript = $previousRuntime !== $process->runtime
                ? $this->runtimeDrivers->for($previousRuntime)->cleanupScript($name)
                : null;
            $applied = $driver->apply($context->node, $app, $process, $workspace, $cleanupScript);

            if (! $applied) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_apply_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' could not be rendered or applied.",
                    'next_command' => 'doctor --family=process --restore',
                ];
            }
        }

        return $warnings;
    }

    /**
     * Restart the rendered runtime units after a successful apply through the
     * process runtime driver selected by `$process->runtime`.
     *
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function restartRuntimeUnits(ProcessOwnerContext $context, Process $process, array $runtimeUnits): array
    {
        $warnings = [];
        $driver = $this->runtimeDrivers->forProcess($process);

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $restarted = $driver->restart($context->node, $name);

            if (! $restarted) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_restart_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' was rendered but could not be restarted.",
                    'next_command' => 'doctor --family=process --restore',
                ];
            }
        }

        return $warnings;
    }
}
