<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Contracts\RemoteShell;
use App\Contracts\RemoteShellStream;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessServiceMetadataPayload;

final readonly class ShowProcessLogs
{
    public function __construct(
        private RemoteShell $remoteShell,
        private RemoteShellStream $remoteShellStream,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private ProcessServiceMetadataPayload $serviceMetadata,
    ) {}

    /**
     * @return array{data: array{logs: array<string, mixed>}, meta: array{line_count: int}}
     */
    public function handle(ProcessOwnerContext $context, string $name, int $lines, bool $follow = false): array
    {
        $target = $this->target($context, $name, $lines, $follow);

        $result = $this->remoteShell->run($target['node'], $target['script']);

        if (! $result->successful()) {
            throw new GatewayApiException('The runtime backend could not read the process log.', 'process.log_read_failed', [
                'process' => $name,
                'runtime_unit' => $target['runtime_unit'],
            ]);
        }

        $parsedLines = $this->parseLines($result->output());

        return [
            'data' => [
                'logs' => [
                    'process' => $target['process']->name,
                    'node' => $target['node']->name,
                    'app' => $context->app?->name,
                    'workspace' => $target['workspace'],
                    'runtime_unit' => $target['runtime_unit'],
                    'service' => $this->serviceMetadata->forProcess($target['process']),
                    'lines' => $parsedLines,
                ],
            ],
            'meta' => [
                'line_count' => count($parsedLines),
            ],
        ];
    }

    /**
     * @return array{node: Node, process: Process, workspace: string|null, runtime_unit: string, script: string}
     */
    public function streamTarget(ProcessOwnerContext $context, string $name, int $lines): array
    {
        return $this->target($context, $name, $lines, true);
    }

    /**
     * @param  array{node: Node, process: Process, workspace: string|null, runtime_unit: string, script: string}  $target
     * @param  callable(string): void  $onOutput
     */
    public function followTarget(array $target, callable $onOutput): void
    {
        $exitCode = $this->remoteShellStream->stream($target['node'], $target['script'], $onOutput);

        if ($exitCode !== 0) {
            throw new GatewayApiException('The runtime backend could not stream the process log.', 'process.log_read_failed', [
                'process' => $target['process']->name,
                'runtime_unit' => $target['runtime_unit'],
            ]);
        }
    }

    /**
     * @return array{node: Node, process: Process, workspace: string|null, runtime_unit: string, script: string}
     */
    private function target(ProcessOwnerContext $context, string $name, int $lines, bool $follow): array
    {
        if ($lines < 1) {
            throw new GatewayApiException('The --lines value must be a positive integer.', 'validation_failed', [
                'field' => 'lines',
                'value' => $lines,
            ]);
        }

        $process = $context->lifecycleProcesses($name)->first();

        if (! $process instanceof Process) {
            throw new GatewayApiException("Process '{$name}' not found for {$context->label()}.", 'process.not_found', $context->errorMeta($name));
        }

        $app = $context->runtimeApp();
        $workspace = $context->runtimeWorkspaceFor($process);
        $driver = $this->runtimeDrivers->forProcess($process);
        $runtimeUnit = $driver->runtimeUnitName($app, $process, $workspace);

        return [
            'node' => $context->node,
            'process' => $process,
            'workspace' => $workspace?->name,
            'runtime_unit' => $runtimeUnit,
            'script' => $driver->logScript($app, $process, $workspace, $runtimeUnit, $lines, $follow),
        ];
    }

    /**
     * @return list<array{timestamp: string|null, message: string}>
     */
    private function parseLines(string $output): array
    {
        return collect(preg_split('/\R/', trim($output)) ?: [])
            ->filter(fn (string $line): bool => $line !== '')
            ->map(function (string $line): array {
                if (preg_match('/^(?<timestamp>\d{4}-\d{2}-\d{2}T[^\s]+)\s+(?<message>.*)$/', $line, $matches) === 1) {
                    return [
                        'timestamp' => $matches['timestamp'],
                        'message' => $matches['message'],
                    ];
                }

                return [
                    'timestamp' => null,
                    'message' => $line,
                ];
            })
            ->values()
            ->all();
    }
}
