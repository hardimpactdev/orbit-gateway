<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Contracts\RemoteShell;
use App\Models\Node;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;

final readonly class WorkspaceSetupStepRunner
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @param  list<WorkspaceStep>  $steps
     * @param  array<string, string>  $env
     * @param  (callable(string, WorkspaceStep, int, int): void)|null  $onProgress
     */
    public function run(
        WorkspaceRun $run,
        array $steps,
        string $path,
        array $env,
        Node $node,
        ?string $containerName = null,
        ?callable $onProgress = null,
    ): bool {
        $run->update(['status' => 'running']);
        $stepCount = count($steps);

        foreach (array_values($steps) as $index => $step) {
            $runStep = WorkspaceRunStep::create([
                'workspace_run_id' => $run->id,
                'workspace_step_id' => $step->id,
                'command' => $step->command,
                'started_at' => now(),
            ]);

            if ($onProgress !== null) {
                $onProgress('running', $step, $index + 1, $stepCount);
            }

            $isContainerized = $containerName !== null && $this->isPhpCommand($step->command);
            $command = $isContainerized
                ? $this->containerCommand($step->command, $containerName, $env)
                : $step->command;

            $result = $this->remoteShell->run($node, $command, [
                'cwd' => $isContainerized ? null : $path,
                'timeout' => $step->timeoutSeconds(),
                'metadata' => $env,
            ]);

            $runStep->update([
                'exit_code' => $result->exitCode,
                'output' => $result->output(),
                'completed_at' => now(),
            ]);

            if (! $result->successful()) {
                if ($onProgress !== null) {
                    $onProgress('failed', $step, $index + 1, $stepCount);
                }

                $run->update(['status' => 'failed', 'completed_at' => now()]);

                return false;
            }

            if ($onProgress !== null) {
                $onProgress('completed', $step, $index + 1, $stepCount);
            }
        }

        $run->update(['status' => 'completed', 'completed_at' => now()]);

        return true;
    }

    private function isPhpCommand(string $command): bool
    {
        $trimmed = ltrim($command);

        return str_starts_with($trimmed, 'php ') || str_starts_with($trimmed, 'composer ');
    }

    /**
     * @param  array<string, string>  $env
     */
    private function containerCommand(string $command, string $containerName, array $env): string
    {
        $parts = ['docker', 'exec', '-w', '/app'];

        foreach ($env as $key => $value) {
            $parts[] = '-e';
            $parts[] = "{$key}={$value}";
        }

        $parts[] = $containerName;
        $parts[] = 'bash';
        $parts[] = '-c';
        $parts[] = $command;

        return implode(' ', array_map(escapeshellarg(...), $parts));
    }
}
