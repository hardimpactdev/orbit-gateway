<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Contracts\RemoteShell;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\Workspaces\WorkspaceRuntimeArtifactRemovalOutcome;
use App\Models\App;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Workspaces\WorkspaceRuntimeContainerManager;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class RemoveWorkspace
{
    public function __construct(
        private RemoteShell $remoteShell,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private WorkspaceRuntimeContainerManager $workspaceRuntimeContainerManager,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     app: string,
     *     action: string,
     *     proxy_routes_removed: int,
     *     processes_removed: int,
     *     worktree_removed: bool,
     *     teardown_steps_run: int,
     *     kept_files: bool,
     *     warnings: list<array<string, string>>
     * }
     */
    public function handle(Workspace $workspace, bool $keepFiles = false): array
    {
        $workspace->loadMissing(['app.node', 'app.processes']);

        $app = $workspace->app;
        $name = $workspace->name;
        $appName = (string) $app?->name;
        $isPhpWorkspace = $app?->runtime_kind === AppRuntimeKind::Php;
        $proxyRouteIds = ProxyRoute::query()
            ->where('workspace_id', $workspace->id)
            ->pluck('id')
            ->all();
        $processCleanupScripts = $this->processCleanupScripts($workspace, $app);
        $teardownSteps = WorkspaceStep::query()
            ->where('app_id', $workspace->app_id)
            ->where('phase', WorkspaceLifecyclePhase::Teardown)
            ->orderBy('sort_order')
            ->get();
        $node = $app?->node;

        DB::transaction(function () use ($workspace, $proxyRouteIds): void {
            if ($proxyRouteIds !== []) {
                ProxyRoute::query()
                    ->whereIn('id', $proxyRouteIds)
                    ->delete();
            }

            $workspace->processes()->delete();
            $workspace->delete();
        });

        $warnings = [];
        $processesRemoved = 0;
        $worktreeRemoved = false;
        $teardownStepsRun = 0;

        if ($node !== null) {
            if ($isPhpWorkspace) {
                try {
                    $containerOutcome = $this->workspaceRuntimeContainerManager->remove($node, $appName, $name);
                } catch (Throwable) {
                    $containerOutcome = WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
                }

                try {
                    $configOutcome = $this->workspaceRuntimeContainerManager->removeRuntimeConfigFile($node, $appName, $name);
                } catch (Throwable) {
                    $configOutcome = WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
                }

                if ($containerOutcome === WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining) {
                    $warnings[] = [
                        'code' => 'workspace.runtime_container_extra',
                        'family' => 'workspace',
                        'message' => "Workspace runtime container for '{$name}' could not be removed during cleanup.",
                        'next_command' => 'doctor --family=workspace --restore',
                    ];
                }

                if ($configOutcome === WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining) {
                    $warnings[] = [
                        'code' => 'workspace.runtime_config_extra',
                        'family' => 'workspace',
                        'message' => "Managed workspace runtime configuration for '{$name}' could not be removed during cleanup.",
                        'next_command' => 'doctor --family=workspace --restore',
                    ];
                }
            }

            $processResult = $this->remoteShell->run($node, $this->renderProcessRemovalScript($processCleanupScripts));
            $processesRemoved = $processResult->successful() ? count($processCleanupScripts) : 0;

            if (! $processResult->successful()) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_extra',
                    'family' => 'process',
                    'message' => 'Workspace inherited runtime units could not be removed during cleanup.',
                    'next_command' => 'doctor --family=process --restore',
                ];
            }

            foreach ($teardownSteps as $teardownStep) {
                $teardownStepsRun++;
                $teardownResult = $this->remoteShell->run($node, $teardownStep->command, [
                    'cwd' => $workspace->path,
                    'timeout' => $teardownStep->timeoutSeconds(),
                    'metadata' => $this->teardownEnvironment($workspace),
                ]);

                if (! $teardownResult->successful()) {
                    $warnings[] = [
                        'code' => 'workspace.teardown_step_failed',
                        'family' => 'workspace',
                        'message' => "Workspace teardown step {$teardownStep->id} failed during cleanup.",
                        'next_command' => 'doctor --family=workspace --restore',
                        'step_id' => (string) $teardownStep->id,
                        'exit_code' => (string) $teardownResult->exitCode,
                    ];
                }
            }

            if (! $keepFiles) {
                $worktreeResult = $this->remoteShell->run($node, 'sudo rm -rf '.escapeshellarg($workspace->path));
                $worktreeRemoved = $worktreeResult->successful();

                if (! $worktreeRemoved) {
                    $warnings[] = [
                        'code' => 'workspace.artifact_extra',
                        'family' => 'workspace',
                        'message' => 'Workspace worktree could not be removed during cleanup.',
                        'next_command' => 'doctor --family=workspace --restore',
                    ];
                }
            }
        }

        return [
            'name' => $name,
            'app' => $appName,
            'action' => 'removed',
            'proxy_routes_removed' => count($proxyRouteIds),
            'processes_removed' => $processesRemoved,
            'worktree_removed' => $worktreeRemoved,
            'teardown_steps_run' => $teardownStepsRun,
            'kept_files' => $keepFiles,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<string>
     */
    private function processCleanupScripts(Workspace $workspace, ?App $app): array
    {
        if (! $app instanceof App) {
            return [];
        }

        return $app->processes
            ->map(function (Process $process) use ($app, $workspace): string {
                $driver = $this->runtimeDrivers->forProcess($process);
                $runtimeUnit = $driver->runtimeUnitName($app, $process, $workspace);

                return $driver->cleanupScript($runtimeUnit);
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $processCleanupScripts
     */
    private function renderProcessRemovalScript(array $processCleanupScripts): string
    {
        if ($processCleanupScripts === []) {
            return 'true';
        }

        return implode("\n", $processCleanupScripts);
    }

    /**
     * @return array<string, string>
     */
    private function teardownEnvironment(Workspace $workspace): array
    {
        return [
            'ORBIT_APP' => (string) $workspace->app?->name,
            'ORBIT_APP_PATH' => (string) $workspace->app?->path,
            'ORBIT_WORKSPACE_NAME' => $workspace->name,
            'ORBIT_WORKSPACE_PATH' => $workspace->path,
            'ORBIT_URL' => $workspace->url(),
            'ORBIT_PHP_VERSION' => (string) $workspace->effectivePhpVersion(),
            'VITE_APP_URL' => $workspace->url(),
            'VITE_VALET_HOST' => (string) parse_url($workspace->url(), PHP_URL_HOST),
        ];
    }
}
