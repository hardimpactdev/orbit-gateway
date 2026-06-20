<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use InvalidArgumentException;

final readonly class EnsureFrankenPhpRuntimeProcess
{
    private const string Command = 'frankenphp';

    public function __construct(
        private AppRuntimeContainerRenderer $appRuntimeContainerRenderer,
        private WorkspaceRuntimeContainerRenderer $workspaceRuntimeContainerRenderer,
    ) {}

    public function forApp(App $app): Process
    {
        $container = $this->appRuntimeContainerRenderer->render($app);

        return $app->processes()->updateOrCreate(
            ['name' => $this->appProcessName($app)],
            [
                'node_id' => $app->node_id,
                'command' => self::Command,
                'restart_policy' => ProcessRestartPolicy::Always,
                'crash_notification' => ProcessCrashNotification::None,
                'runtime' => ProcessRuntime::Docker,
                'tool' => null,
                'runtime_config' => [
                    'container_name' => $container->name(),
                    'container_spec_hash' => $container->specHash(),
                    'container_spec_hash_label' => AppRuntimeContainer::SpecHashLabel,
                    'document_root' => $app->document_root,
                    'php_ini_path' => $this->appRuntimeContainerRenderer->phpIniHostPath($app),
                    'php_version' => $app->php_version,
                    'source_path' => $app->path,
                ],
                'sort_order' => 0,
            ],
        );
    }

    public function forWorkspace(Workspace $workspace): Process
    {
        $workspace->loadMissing('app');
        $app = $workspace->app;

        if (! $app instanceof App) {
            throw new InvalidArgumentException("Workspace '{$workspace->name}' has no owning app; cannot ensure FrankenPHP runtime process.");
        }

        $container = $this->workspaceRuntimeContainerRenderer->render($workspace);

        return $workspace->processes()->updateOrCreate(
            ['name' => $this->workspaceProcessName($workspace)],
            [
                'node_id' => $app->node_id,
                'command' => self::Command,
                'restart_policy' => ProcessRestartPolicy::Always,
                'crash_notification' => ProcessCrashNotification::None,
                'runtime' => ProcessRuntime::Docker,
                'tool' => null,
                'runtime_config' => [
                    'container_name' => $container->name(),
                    'container_spec_hash' => $container->specHash(),
                    'container_spec_hash_label' => WorkspaceRuntimeContainer::SpecHashLabel,
                    'document_root' => $app->document_root,
                    'php_ini_path' => $this->workspaceRuntimeContainerRenderer->phpIniHostPath($workspace),
                    'php_version' => $workspace->effectivePhpVersion(),
                    'source_path' => $workspace->path,
                ],
                'sort_order' => 0,
            ],
        );
    }

    public function appProcessName(App $app): string
    {
        return "frankenphp-{$app->name}";
    }

    public function workspaceProcessName(Workspace $workspace): string
    {
        $workspace->loadMissing('app');

        if (! $workspace->app instanceof App) {
            throw new InvalidArgumentException("Workspace '{$workspace->name}' has no owning app; cannot name FrankenPHP runtime process.");
        }

        return "frankenphp-{$workspace->app->name}-{$workspace->name}";
    }
}
