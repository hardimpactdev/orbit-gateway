<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class ProcessListPayload
{
    public function __construct(
        private readonly ProcessOwnerContextResolver $contexts,
        private readonly ProcessRuntimeDriverRegistry $runtimeDrivers,
        private readonly ProcessServiceMetadataPayload $serviceMetadata,
    ) {}

    /**
     * @return array{context: array{node: string, app: string|null, workspace: string|null}, processes: list<array<string, mixed>>}
     */
    public function forContext(?string $nodeName, ?string $appName, ?string $workspaceName, ?Node $caller = null): array
    {
        $context = $this->contexts->resolveVisible(
            nodeName: $nodeName,
            appName: $appName,
            workspaceName: $workspaceName,
            caller: $caller,
            permission: 'process:read',
            allowSingleVisibleAppDefault: true,
        );
        $app = $context->runtimeApp();
        $processes = $context->lifecycleProcesses(null);

        return [
            'context' => $context->payloadContext(),
            'processes' => $processes
                ->map(function (Process $process) use ($context, $app): array {
                    $workspace = $context->runtimeWorkspaceFor($process);
                    $driver = $this->runtimeDrivers->forProcess($process);

                    return [
                        'node' => $context->node->name,
                        'app' => $context->app?->name,
                        'workspace' => $workspace?->name,
                        'name' => $process->name,
                        'command' => $process->command,
                        'restart_policy' => $process->restart_policy->value,
                        'crash_notification' => $process->crash_notification->value,
                        'runtime' => $process->runtime->value,
                        'tool' => $process->tool,
                        'service' => $this->serviceMetadata->forProcess($process),
                        'runtime_unit' => $driver->runtimeUnitName($app, $process, $workspace),
                        'last_event' => $this->lastEvent($process, $workspace),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{id: int, type: string}|null
     */
    private function lastEvent(Process $process, ?Workspace $workspace): ?array
    {
        $event = ProcessEvent::query()
            ->where('process_id', $process->id)
            ->when(
                $workspace instanceof Workspace,
                fn (Builder $query): Builder => $query->where('workspace_id', $workspace->id),
                fn (Builder $query): Builder => $query->whereNull('workspace_id'),
            )
            ->latest('recorded_at')
            ->latest('id')
            ->first();

        if (! $event instanceof ProcessEvent) {
            return null;
        }

        return [
            'id' => $event->id,
            'type' => $event->event->value,
        ];
    }
}
