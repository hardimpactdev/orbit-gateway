<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeUnitPayload;
use App\Services\Processes\ProcessServiceDefinitionRegistry;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class AddProcess
{
    public function __construct(
        private EnsureAppProcessRuntimeUnits $ensureRuntimeUnits,
        private ProcessRuntimeUnitPayload $runtimeUnitPayload,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private ProcessServiceDefinitionRegistry $serviceDefinitions,
    ) {}

    /**
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function handle(
        ProcessOwnerContext $context,
        string $name,
        ?string $command,
        ProcessRestartPolicy $restartPolicy,
        ProcessCrashNotification $crashNotification,
        bool $start,
        ?ProcessRuntime $runtime = null,
        ?string $tool = null,
        ?string $definition = null,
        ?string $version = null,
    ): array {
        $app = $context->runtimeApp();
        $app->loadMissing(['node', 'workspaces']);

        $resolvedRuntime = $runtime ?? ($definition === null ? $context->defaultRuntime() : ProcessRuntime::Docker);
        $runtimeConfig = [];

        if ($definition !== null) {
            if (! $context->owner instanceof Node) {
                throw new GatewayApiException('Process definitions are only valid for node-owned service processes.', 'validation_failed', [
                    'field' => 'definition',
                    'value' => $definition,
                    'reason' => 'process_definition_requires_node_owned_process',
                ]);
            }

            if ($tool !== null) {
                throw new GatewayApiException('Service process definitions do not use tool dependencies.', 'validation_failed', [
                    'field' => 'tool',
                    'value' => $tool,
                    'reason' => 'process_definition_cannot_reference_tool',
                ]);
            }

            $context->assertRuntimeAllowed($resolvedRuntime);

            $serviceDefinition = $this->serviceDefinitions->resolve(
                definition: $definition,
                version: $version,
                runtime: $resolvedRuntime,
                node: $context->node,
                processName: $name,
            );

            $command = $serviceDefinition->command;
            $runtimeConfig = $serviceDefinition->runtimeConfig;
        } else {
            $context->assertRuntimeAllowed($resolvedRuntime);
        }

        if ($command === null || trim($command) === '') {
            throw new GatewayApiException('The process command is required.', 'validation_failed', [
                'field' => 'command',
            ]);
        }

        if ($context->ownerProcesses()->where('name', $name)->exists()) {
            throw new GatewayApiException("Process '{$name}' already exists for {$context->label()}.", 'process.name_collision', $context->errorMeta($name));
        }

        if ($runtimeConfig !== []) {
            $this->assertServiceDefinitionHasNoResourceConflicts($context, $name, $runtimeConfig);
        }

        $process = DB::transaction(function () use ($context, $name, $command, $restartPolicy, $crashNotification, $resolvedRuntime, $tool, $runtimeConfig): Process {
            $maxOrder = $context->ownerProcesses()
                ->lockForUpdate()
                ->max('sort_order') ?? 0;

            $process = $context->ownerProcesses()->create([
                'node_id' => $context->node->id,
                'name' => $name,
                'command' => $command,
                'restart_policy' => $restartPolicy,
                'crash_notification' => $crashNotification,
                'runtime' => $resolvedRuntime,
                'tool' => $tool,
                'runtime_config' => $runtimeConfig,
                'sort_order' => $maxOrder + 1,
            ]);

            if (! $process instanceof Process) {
                throw new LogicException('Process owner relation created an unexpected model.');
            }

            return $process;
        });

        $app->unsetRelation('processes');
        $runtimeUnits = $this->runtimeUnitPayload->forProcess($app, $process, $context->runtimeWorkspaceFor($process));
        $warnings = $context->app instanceof App && $context->workspace === null
            ? $this->ensureRuntimeUnits->handle($app)
            : $this->applyRuntimeUnits($context, $app, $process, $runtimeUnits);

        if ($start) {
            $warnings = [
                ...$warnings,
                ...$this->startRuntimeUnits($context, $process, $runtimeUnits),
            ];
        }

        return [
            'data' => [
                'process' => [
                    ...$context->processPayload($process),
                ],
                'runtime_units' => $runtimeUnits,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function applyRuntimeUnits(ProcessOwnerContext $context, App $app, Process $process, array $runtimeUnits): array
    {
        $warnings = [];
        $driver = $this->runtimeDrivers->forProcess($process);

        foreach ($runtimeUnits as $runtimeUnit) {
            $workspace = $context->runtimeWorkspaceFor($process);
            $applied = $driver->apply($context->node, $app, $process, $workspace);

            if (! $applied) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_apply_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$runtimeUnit['name']}' could not be rendered or applied.",
                    'next_command' => 'doctor --family=process --restore',
                ];
            }
        }

        return $warnings;
    }

    /**
     * Start the rendered runtime units after a successful apply through the
     * process runtime driver selected by `$process->runtime`.
     *
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function startRuntimeUnits(ProcessOwnerContext $context, Process $process, array $runtimeUnits): array
    {
        $warnings = [];
        $driver = $this->runtimeDrivers->forProcess($process);

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $started = $driver->start($context->node, $name);

            if (! $started) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_start_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' was rendered but could not be started.",
                    'next_command' => 'doctor --family=process --restore',
                ];
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     */
    private function assertServiceDefinitionHasNoResourceConflicts(ProcessOwnerContext $context, string $name, array $runtimeConfig): void
    {
        $requestedEndpoints = $this->endpoints($runtimeConfig);
        $requestedVolumeNames = $this->volumeNames($runtimeConfig);

        $processes = Process::query()
            ->where('node_id', $context->node->id)
            ->get();

        foreach ($processes as $process) {
            $config = is_array($process->runtime_config) ? $process->runtime_config : [];

            foreach ($requestedEndpoints as $endpoint) {
                foreach ($this->endpoints($config) as $existingEndpoint) {
                    if ($endpoint['port'] !== $existingEndpoint['port']) {
                        continue;
                    }

                    throw new GatewayApiException("Process '{$name}' endpoint port {$endpoint['port']} conflicts with process '{$process->name}'.", 'validation_failed', [
                        'field' => 'definition',
                        'reason' => 'endpoint_conflict',
                        'node' => $context->node->name,
                        'process' => $name,
                        'existing_process' => $process->name,
                        'port' => $endpoint['port'],
                    ]);
                }
            }

            foreach ($requestedVolumeNames as $volumeName) {
                if (! in_array($volumeName, $this->volumeNames($config), true)) {
                    continue;
                }

                throw new GatewayApiException("Process '{$name}' volume '{$volumeName}' conflicts with process '{$process->name}'.", 'validation_failed', [
                    'field' => 'definition',
                    'reason' => 'volume_conflict',
                    'node' => $context->node->name,
                    'process' => $name,
                    'existing_process' => $process->name,
                    'volume' => $volumeName,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{name: string|null, port: int}>
     */
    private function endpoints(array $config): array
    {
        $rawEndpoints = [];

        if (is_array($config['endpoint'] ?? null)) {
            $rawEndpoints[] = $config['endpoint'];
        }

        if (is_array($config['endpoints'] ?? null)) {
            foreach ($config['endpoints'] as $endpoint) {
                if (is_array($endpoint)) {
                    $rawEndpoints[] = $endpoint;
                }
            }
        }

        $endpoints = [];

        foreach ($rawEndpoints as $endpoint) {
            $port = (int) ($endpoint['port'] ?? 0);

            if ($port < 1) {
                continue;
            }

            $name = is_string($endpoint['name'] ?? null) ? trim($endpoint['name']) : null;

            $endpoints[] = [
                'name' => $name !== '' ? $name : null,
                'port' => $port,
            ];
        }

        return $endpoints;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function volumeNames(array $config): array
    {
        $volumes = [];

        foreach (['mounts', 'volumes'] as $key) {
            if (! is_array($config[$key] ?? null)) {
                continue;
            }

            foreach ($config[$key] as $volume) {
                if (! is_array($volume)) {
                    continue;
                }

                foreach (['name', 'source'] as $nameKey) {
                    $name = is_string($volume[$nameKey] ?? null) ? trim($volume[$nameKey]) : '';

                    if ($name !== '') {
                        $volumes[] = $name;
                    }
                }
            }
        }

        return array_values(array_unique($volumes));
    }
}
