<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Http\Gateway\GatewayApiException;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeUnitPayload;

final readonly class RemoveProcess
{
    public function __construct(
        private ProcessRuntimeUnitPayload $runtimeUnitPayload,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function handle(ProcessOwnerContext $context, string $name): array
    {
        $app = $context->runtimeApp();
        $app->loadMissing(['node', 'workspaces']);

        $process = $context->ownerProcesses()
            ->where('name', $name)
            ->first();

        if (! $process instanceof Process) {
            throw new GatewayApiException("Process '{$name}' not found for {$context->label()}.", 'process.not_found', $context->errorMeta($name));
        }

        $runtimeUnits = $this->runtimeUnitPayload->forProcess($app, $process, $context->runtimeWorkspaceFor($process));
        $warnings = $this->removeRuntimeUnits($context, $process, $runtimeUnits);
        $process->delete();

        return [
            'data' => [
                'process' => [
                    'name' => $name,
                    ...$context->payloadContext(),
                ],
                'removed_runtime_units' => array_column($runtimeUnits, 'name'),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function removeRuntimeUnits(ProcessOwnerContext $context, Process $process, array $runtimeUnits): array
    {
        $warnings = [];

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $ok = $this->removeRuntimeUnit($context, $process, $name);

            if (! $ok) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_extra',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' may still exist after process intent removal.",
                    'next_command' => 'doctor --family=process --restore',
                ];
            }
        }

        return $warnings;
    }

    private function removeRuntimeUnit(ProcessOwnerContext $context, Process $process, string $name): bool
    {
        return $this->runtimeDrivers->forProcess($process)->remove($context->node, $name);
    }
}
