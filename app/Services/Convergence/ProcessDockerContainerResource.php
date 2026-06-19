<?php

declare(strict_types=1);

namespace App\Services\Convergence;

use App\Contracts\RemoteShell;
use App\Data\Convergence\ConvergenceApplyResult;
use App\Data\Convergence\ProcessDockerContainerPlan;
use App\Data\Convergence\ProcessDockerContainerProbe;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Enums\Processes\ProcessDockerContainerApplyOutcome;
use App\Models\Node;
use App\Services\Processes\ProcessDockerContainer;
use App\Services\Runtime\DockerCommandBuilder;
use RuntimeException;

final readonly class ProcessDockerContainerResource
{
    public function __construct(
        private ProcessDockerContainer $container,
        private DockerCommandBuilder $commands,
    ) {}

    public function ensureNetwork(Node $node, RemoteShell $remoteShell): void
    {
        $result = $remoteShell->run($node, $this->commands->networkInspect($this->container->network()), ['throw' => false]);

        if ($result->successful()) {
            return;
        }

        $create = $remoteShell->run($node, $this->commands->networkCreate($this->container->network()), ['throw' => false]);

        if ($create->successful()) {
            return;
        }

        throw new RuntimeException($this->failureMessage(
            $node,
            "create {$this->container->network()} Docker network",
            $create,
        ));
    }

    public function probe(Node $node, RemoteShell $remoteShell): ProcessDockerContainerProbe
    {
        $result = $remoteShell->run($node, $this->commands->containerInspect($this->container->name()), ['throw' => false]);

        if (! $result->successful()) {
            return new ProcessDockerContainerProbe(
                reachable: true,
                exists: false,
                error: trim($result->stderr) !== '' ? trim($result->stderr) : null,
            );
        }

        $output = trim($result->stdout);

        if ($output === '') {
            return new ProcessDockerContainerProbe(
                reachable: true,
                exists: false,
            );
        }

        $inspection = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($inspection)) {
            throw new RuntimeException("Docker returned an invalid inspect payload for {$this->container->name()} on {$node->name}.");
        }

        return new ProcessDockerContainerProbe(
            reachable: true,
            exists: true,
            specHash: $this->observedSpecHash($inspection),
            inspection: $inspection,
        );
    }

    public function plan(ProcessDockerContainerProbe $probe): ProcessDockerContainerPlan
    {
        if (! $probe->reachable) {
            return new ProcessDockerContainerPlan(
                status: ConvergenceStatus::Unreachable,
                summary: "Could not inspect Docker process container {$this->container->name()}.",
                outcome: ProcessDockerContainerApplyOutcome::Unchanged,
                details: $this->details(['error' => $probe->error]),
            );
        }

        if (! $probe->exists) {
            return new ProcessDockerContainerPlan(
                status: ConvergenceStatus::Changed,
                summary: "Create Docker process container {$this->container->name()}.",
                outcome: ProcessDockerContainerApplyOutcome::Created,
                details: $this->details([
                    'observed_hash' => null,
                    'outcome' => ProcessDockerContainerApplyOutcome::Created->value,
                ]),
            );
        }

        if (! hash_equals($this->container->specHash(), $probe->specHash ?? '')) {
            return new ProcessDockerContainerPlan(
                status: ConvergenceStatus::Changed,
                summary: "Recreate Docker process container {$this->container->name()}.",
                outcome: ProcessDockerContainerApplyOutcome::Recreated,
                details: $this->details([
                    'observed_hash' => $probe->specHash,
                    'outcome' => ProcessDockerContainerApplyOutcome::Recreated->value,
                ]),
            );
        }

        return new ProcessDockerContainerPlan(
            status: ConvergenceStatus::Ok,
            summary: "Docker process container {$this->container->name()} already matches gateway intent.",
            outcome: ProcessDockerContainerApplyOutcome::Unchanged,
            details: $this->details([
                'observed_hash' => $probe->specHash,
                'outcome' => ProcessDockerContainerApplyOutcome::Unchanged->value,
            ]),
        );
    }

    public function apply(Node $node, RemoteShell $remoteShell, ProcessDockerContainerPlan $plan): ConvergenceApplyResult
    {
        if (! $plan->shouldApply()) {
            return new ConvergenceApplyResult(
                status: $plan->status,
                summary: $plan->summary,
                details: $plan->details,
            );
        }

        if ($plan->outcome === ProcessDockerContainerApplyOutcome::Recreated) {
            $remove = $remoteShell->run($node, $this->commands->containerRemove($this->container->name()), ['throw' => false]);

            if (! $remove->successful()) {
                return $this->failedResult($node, "remove drifted {$this->container->name()} container", $remove, $plan);
            }
        }

        $create = $remoteShell->run($node, $this->commands->createIdle($this->container), ['throw' => false]);

        if (! $create->successful()) {
            return $this->failedResult($node, "create {$this->container->name()} container", $create, $plan);
        }

        return new ConvergenceApplyResult(
            status: ConvergenceStatus::Changed,
            summary: "Applied Docker process container {$this->container->name()}.",
            details: $plan->details,
        );
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function observedSpecHash(array $inspection): ?string
    {
        $labels = $inspection['Config']['Labels'] ?? [];

        if (! is_array($labels)) {
            return null;
        }

        $hash = $labels[ProcessDockerContainer::SpecHashLabel] ?? null;

        return is_string($hash) ? $hash : null;
    }

    private function failedResult(Node $node, string $step, RemoteShellResult $result, ProcessDockerContainerPlan $plan): ConvergenceApplyResult
    {
        return new ConvergenceApplyResult(
            status: ConvergenceStatus::Failed,
            summary: $this->failureMessage($node, $step, $result),
            details: $this->details([
                'outcome' => $plan->outcome->value,
                'exit_code' => $result->exitCode,
                'error' => trim($result->stderr) !== '' ? trim($result->stderr) : null,
            ]),
        );
    }

    private function failureMessage(Node $node, string $step, RemoteShellResult $result): string
    {
        $output = trim($result->errorOutput().' '.$result->stdout);
        $message = $output !== '' ? $output : 'unknown error';

        return "Failed to {$step} on {$node->name}: {$message}";
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function details(array $extra = []): array
    {
        return [
            'container' => $this->container->name(),
            'network' => $this->container->network(),
            'expected_hash' => $this->container->specHash(),
            ...array_filter($extra, fn (mixed $value): bool => $value !== null),
        ];
    }
}
