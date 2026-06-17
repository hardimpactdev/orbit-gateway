<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\RemoteShell;
use App\Enums\Apps\AppRuntimeArtifactRemovalOutcome;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Tools\CaddyTool;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class RemoveApp
{
    public function __construct(
        private RemoteShell $remoteShell,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private AppRuntimeContainerManager $appRuntimeContainerManager,
    ) {}

    /**
     * @return array{
     *     app: array<string, mixed>,
     *     result: array{action: string},
     *     cleanup: array{
     *         proxy_routes_removed: int,
     *         workspaces_removed: int,
     *         schedules_removed: int,
     *         processes_removed: int,
     *         runtime_container_removed: bool,
     *         runtime_config_removed: bool,
     *     },
     *     warnings: list<array<string, string>>
     * }
     */
    public function handle(App $app): array
    {
        $app->loadMissing(['node', 'processes']);

        $appPayload = $this->appPayload($app);
        $appName = $app->name;
        $isPhpApp = $app->runtime_kind === AppRuntimeKind::Php;
        $processCleanupScripts = $this->processCleanupScripts($app);
        $proxyRouteIds = ProxyRoute::query()
            ->where('app_id', $app->id)
            ->pluck('id')
            ->all();
        $workspacesRemoved = Workspace::query()
            ->where('app_id', $app->id)
            ->count();
        $schedulesRemoved = Schedule::query()
            ->where('app_id', $app->id)
            ->count();
        $processesRemoved = $app->processes()->count();
        $removeAppPath = ! $app->adopted
            && App::query()
                ->where('id', '!=', $app->id)
                ->where('node_id', $app->node_id)
                ->where('path', $app->path)
                ->doesntExist();

        DB::transaction(function () use ($app, $proxyRouteIds): void {
            $workspaceIds = Workspace::query()
                ->where('app_id', $app->id)
                ->pluck('id')
                ->all();

            $app->processes()->delete();

            if ($workspaceIds !== []) {
                Process::query()
                    ->where('owner_type', Workspace::class)
                    ->whereIn('owner_id', $workspaceIds)
                    ->delete();
            }

            $app->delete();

            if ($proxyRouteIds !== []) {
                ProxyRoute::query()
                    ->whereIn('id', $proxyRouteIds)
                    ->delete();
            }
        });

        $containerOutcome = AppRuntimeArtifactRemovalOutcome::AlreadyAbsent;
        $configOutcome = AppRuntimeArtifactRemovalOutcome::AlreadyAbsent;
        $warnings = [];

        if ($app->node !== null) {
            if ($isPhpApp) {
                try {
                    $containerOutcome = $this->appRuntimeContainerManager->remove($app->node, $appName);
                } catch (Throwable) {
                    $containerOutcome = AppRuntimeArtifactRemovalOutcome::FailedRemaining;
                }

                try {
                    $configOutcome = $this->appRuntimeContainerManager->removeRuntimeConfigFile($app->node, $appName);
                } catch (Throwable) {
                    $configOutcome = AppRuntimeArtifactRemovalOutcome::FailedRemaining;
                }
            }

            if ($containerOutcome === AppRuntimeArtifactRemovalOutcome::FailedRemaining) {
                $warnings[] = [
                    'code' => 'app.runtime_container_extra',
                    'family' => 'app',
                    'message' => "App runtime container for '{$appName}' could not be removed during cleanup.",
                    'next_command' => 'doctor --family=app --restore',
                ];
            }

            if ($configOutcome === AppRuntimeArtifactRemovalOutcome::FailedRemaining) {
                $warnings[] = [
                    'code' => 'app.runtime_config_extra',
                    'family' => 'app',
                    'message' => "Managed app runtime configuration for '{$appName}' could not be removed during cleanup.",
                    'next_command' => 'doctor --family=app --restore',
                ];
            }

            // Best-effort cleanup of non-runtime artifacts (caddy site,
            // process runtime units, optional app path). Failures here surface as
            // proxy/process drift through their own families on the next
            // doctor pass.
            $this->remoteShell->run(
                $app->node,
                $this->renderNonRuntimeCleanupScript($app, $processCleanupScripts, $removeAppPath),
            );
        }

        return [
            'app' => $appPayload,
            'result' => ['action' => 'removed'],
            'cleanup' => [
                'proxy_routes_removed' => count($proxyRouteIds),
                'workspaces_removed' => $workspacesRemoved,
                'schedules_removed' => $schedulesRemoved,
                'processes_removed' => $processesRemoved,
                'runtime_container_removed' => $containerOutcome === AppRuntimeArtifactRemovalOutcome::Removed,
                'runtime_config_removed' => $configOutcome === AppRuntimeArtifactRemovalOutcome::Removed,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'runtime_kind' => $app->runtime_kind->value,
            'php_version' => $app->php_version,
            'worker_enabled' => $app->worker_enabled,
            'worker_config' => is_array($app->worker_config) ? $app->worker_config : null,
            'adopted' => $app->adopted,
        ];
    }

    /**
     * @return list<string>
     */
    private function processCleanupScripts(App $app): array
    {
        return $app->processes
            ->map(function (Process $process) use ($app): string {
                $driver = $this->runtimeDrivers->forProcess($process);
                $runtimeUnit = $driver->runtimeUnitName($app, $process);

                return $driver->cleanupScript($runtimeUnit);
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $processCleanupScripts
     */
    private function renderNonRuntimeCleanupScript(App $app, array $processCleanupScripts, bool $removeAppPath): string
    {
        $domain = parse_url($app->url(), PHP_URL_HOST) ?: $app->name;
        $commands = [
            'sudo rm -f '.escapeshellarg("/etc/caddy/sites/{$domain}.caddy"),
        ];

        array_push($commands, ...$processCleanupScripts);

        $commands[] = CaddyTool::reloadCommand().' || true';

        if ($removeAppPath) {
            $commands[] = 'rm -rf '.escapeshellarg($app->path);
        }

        return implode("\n", $commands);
    }
}
