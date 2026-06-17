<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Contracts\RemoteShell;
use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Gateway\GatewaySwarmManager;
use App\Services\Nodes\Roles\NodeRoleAssignments;

class FleetUpdateVerifier
{
    public function __construct(
        private readonly GatewaySwarmManager $swarm,
        private readonly NodeRoleAssignments $roles,
        private readonly RemoteShell $remoteShell,
        private readonly OperationRunRecorder $operationRuns,
        private readonly FleetUpdateTargetSelector $targets,
    ) {}

    public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->runVerificationStep(
            $operationRun,
            'verification.gateway',
            'Verifying orbit-gateway service',
            'orbit-gateway service verified',
            fn (): null => $this->verifyGatewayService(),
        );
        $this->runVerificationStep(
            $operationRun,
            'verification.scheduler',
            'Verifying orbit-scheduler service',
            'orbit-scheduler service verified',
            fn (): null => $this->verifySchedulerService(),
        );
        $this->runVerificationStep(
            $operationRun,
            'verification.cli',
            'Verifying workload CLI artifacts',
            'Workload CLI artifacts verified',
            fn (): null => $this->verifyWorkloadCli($operationRun),
        );
        $this->runVerificationStep(
            $operationRun,
            'verification.role-images',
            'Verifying required role images',
            'Required role images verified',
            fn (): null => $this->verifyRequiredRoleImages($operationRun, $plan),
        );
    }

    private function verifyGatewayService(): null
    {
        if ($this->swarm->serviceImage('orbit_orbit-gateway') === null) {
            throw new FleetUpdateVerificationFailed('gateway_health_failed', 'Gateway service verification failed.');
        }

        return null;
    }

    private function verifySchedulerService(): null
    {
        if ($this->swarm->serviceImage('orbit_orbit-scheduler') === null) {
            throw new FleetUpdateVerificationFailed('scheduler_health_failed', 'Scheduler service verification failed.');
        }

        return null;
    }

    private function verifyWorkloadCli(OperationRun $operationRun): null
    {
        foreach ($this->targets->workloadNodes() as $node) {
            $result = $this->remoteShell->run($node, 'orbit --version', [
                'cwd' => $node->orbit_path,
                'timeout' => 30,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => $operationRun->id,
                ],
            ]);

            if (! $result->successful()) {
                throw new FleetUpdateVerificationFailed('cli_verification_failed', 'CLI verification failed.');
            }
        }

        return null;
    }

    private function verifyRequiredRoleImages(OperationRun $operationRun, OperationUpdatePlan $plan): null
    {
        foreach ($this->targets->workloadNodes() as $node) {
            $images = $this->requiredRoleImages($plan, $node);

            if ($images === []) {
                continue;
            }

            $script = collect($images)
                ->map(fn (string $image): string => 'docker image inspect '.escapeshellarg($image).' >/dev/null')
                ->implode("\n");

            $result = $this->remoteShell->run($node, $script, [
                'cwd' => $node->orbit_path,
                'timeout' => 60,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => $operationRun->id,
                ],
            ]);

            if (! $result->successful()) {
                throw new FleetUpdateVerificationFailed('required_image_missing', 'Required role image verification failed.');
            }
        }

        return null;
    }

    private function runVerificationStep(OperationRun $operationRun, string $key, string $runningMessage, string $doneMessage, callable $callback): null
    {
        $this->operationRuns->appendStep($operationRun->id, $key, 'running', $runningMessage);

        try {
            $callback();
        } catch (FleetUpdateVerificationFailed $exception) {
            $this->operationRuns->appendStep($operationRun->id, $key, 'fail', $exception->publicMessage);

            throw $exception;
        }

        $this->operationRuns->appendStep($operationRun->id, $key, 'done', $doneMessage);

        return null;
    }

    /**
     * @return list<string>
     */
    private function requiredRoleImages(OperationUpdatePlan $plan, Node $node): array
    {
        $images = [];

        if ($this->roles->nodeHostsOrbitCaddy($node) && is_string($plan->role_images['orbit-caddy'] ?? null)) {
            $images[] = $plan->role_images['orbit-caddy'];
        }

        if ($this->roles->nodeHasActiveRole($node, NodeRoleName::WebSocket->value) && is_string($plan->role_images['orbit-websocket'] ?? null)) {
            $images[] = $plan->role_images['orbit-websocket'];
        }

        return array_values(array_unique($images));
    }
}
