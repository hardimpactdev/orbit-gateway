<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessDockerContainerApplyOutcome;
use App\Exceptions\ProcessDockerContainerApplyException;
use App\Models\Node;
use App\Services\Convergence\ProcessDockerContainerResource;
use App\Services\Runtime\DockerCommandBuilder;
use Throwable;

final readonly class ProcessDockerRuntimeManager
{
    public function __construct(
        private RemoteShell $remoteShell,
        private DockerCommandBuilder $commands,
    ) {}

    /**
     * Converge the rendered process runtime artifact on the node.
     *
     * The container is created in Docker's `Created` state (i.e. not
     * running). The contract from process:add is that `--start` controls
     * whether the rendered unit starts; apply only converges the artifact
     * shape. Lifecycle commands (process:start / --start / --restart) are
     * the only callers that flip the container into the running state.
     */
    public function apply(Node $node, ProcessDockerContainer $container): ProcessDockerContainerApplyOutcome
    {
        $resource = new ProcessDockerContainerResource($container, $this->commands);

        $resource->ensureNetwork($node, $this->remoteShell);

        $probe = $resource->probe($node, $this->remoteShell);
        $hadExistingContainer = $probe->exists;

        try {
            $plan = $resource->plan($probe);
            $result = $resource->apply($node, $this->remoteShell, $plan);

            if (! $result->successful()) {
                throw new ProcessDockerContainerApplyException(
                    hadExistingContainer: $hadExistingContainer,
                    message: $result->summary,
                );
            }

            return $plan->outcome;
        } catch (ProcessDockerContainerApplyException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ProcessDockerContainerApplyException(
                hadExistingContainer: $hadExistingContainer,
                message: $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    public function remove(Node $node, string $containerName): bool
    {
        $inspect = $this->run($node, $this->commands->containerInspect($containerName));

        if ($inspect->successful() && trim($inspect->stdout) !== '') {
            return $this->run($node, $this->commands->containerRemove($containerName))->successful();
        }

        return $this->isDockerNoSuchObject($inspect);
    }

    /**
     * Lifecycle hook used by AddProcess --start / EditProcess --restart.
     * Returns true when the lifecycle command succeeded.
     */
    public function start(Node $node, string $containerName): bool
    {
        return $this->run($node, $this->commands->containerStart($containerName))->successful();
    }

    public function stop(Node $node, string $containerName): bool
    {
        return $this->run($node, $this->commands->containerStop($containerName))->successful();
    }

    public function restart(Node $node, string $containerName): bool
    {
        return $this->run($node, $this->commands->containerRestart($containerName))->successful();
    }

    private function isDockerNoSuchObject(RemoteShellResult $result): bool
    {
        $message = $result->stderr.' '.$result->stdout;

        return preg_match('/No such (object|container)/i', $message) === 1;
    }

    private function run(Node $node, string $script): RemoteShellResult
    {
        return $this->remoteShell->run($node, $script);
    }
}
