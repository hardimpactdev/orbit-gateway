<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Gateway\GatewaySwarmStackRenderer;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class UpdateRunnerLauncher
{
    private const string ContainerConfigRoot = '/home/orbit/.config/orbit';

    public function __construct(
        private OperationUpdatePlanStore $plans,
    ) {}

    public function launch(OperationRun|string $operationRun): ProcessResult
    {
        $operationRunId = $this->operationRunId($operationRun);
        $plan = $this->plans->forOperationRun($operationRunId);

        if (! $plan instanceof OperationUpdatePlan) {
            throw new RuntimeException("Operation update plan for run [{$operationRunId}] was not found.");
        }

        $configRoot = $this->configRoot();
        File::ensureDirectoryExists($configRoot, 0700);

        $result = Process::timeout(60)->run($this->dockerRunCommand(
            operationRunId: $operationRunId,
            image: $plan->gateway_image,
            hostConfigRoot: $configRoot,
        ));

        if ($result->successful()) {
            return $result;
        }

        $message = trim($result->errorOutput().$result->output());

        throw new RuntimeException("Failed to launch update runner for operation run [{$operationRunId}]: {$message}");
    }

    private function operationRunId(OperationRun|string $operationRun): string
    {
        $operationRunId = $operationRun instanceof OperationRun ? $operationRun->id : trim($operationRun);

        if ($operationRunId === '') {
            throw new RuntimeException('Update runner operation_run_id cannot be empty.');
        }

        return $operationRunId;
    }

    private function dockerRunCommand(string $operationRunId, string $image, string $hostConfigRoot): string
    {
        return implode(' ', [
            'docker run',
            '--rm',
            '--detach',
            '--name '.$this->escape($this->containerName($operationRunId)),
            '--label '.$this->escape("orbit.operation_run_id={$operationRunId}"),
            '--label '.$this->escape('orbit.role=update-runner'),
            '--network '.$this->escape(GatewaySwarmStackRenderer::Network),
            '--mount '.$this->escape('type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock'),
            '--mount '.$this->escape("type=bind,source={$hostConfigRoot},target=".self::ContainerConfigRoot),
            '--env '.$this->escape('ORBIT_CONFIG_ROOT='.self::ContainerConfigRoot),
            $this->escape($image),
            $this->escape('artisan'),
            $this->escape('orbit:update-runner'),
            $this->escape("--operation-run-id={$operationRunId}"),
        ]);
    }

    private function containerName(string $operationRunId): string
    {
        $suffix = preg_replace('/[^a-zA-Z0-9_.-]/', '-', $operationRunId) ?: 'unknown';

        return 'orbit-update-runner-'.$suffix;
    }

    private function configRoot(): string
    {
        $configRoot = config('orbit.paths.config_root', '/home/orbit/.config/orbit');

        if (! is_string($configRoot) || trim($configRoot) === '') {
            throw new RuntimeException('Orbit config root is not configured.');
        }

        return rtrim($configRoot, '/');
    }

    private function escape(string $value): string
    {
        return escapeshellarg($value);
    }
}
