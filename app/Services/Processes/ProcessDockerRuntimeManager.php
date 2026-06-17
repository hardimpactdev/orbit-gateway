<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessDockerContainerApplyOutcome;
use App\Exceptions\ProcessDockerContainerApplyException;
use App\Models\Node;
use App\Services\Runtime\DockerCommandBuilder;
use RuntimeException;
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
        $this->ensureNetwork($node, $container);

        $inspection = $this->inspect($node, $container);
        $hadExistingContainer = $inspection !== null;

        try {
            if ($inspection === null) {
                $this->createContainer($node, $container);

                return ProcessDockerContainerApplyOutcome::Created;
            }

            if (! $this->matchesSpec($inspection, $container)) {
                $this->runRequired(
                    $node,
                    $this->commands->containerRemove($container->name()),
                    "remove drifted {$container->name()} container",
                );
                $this->createContainer($node, $container);

                return ProcessDockerContainerApplyOutcome::Recreated;
            }

            // Existing container matches the rendered spec. Apply does not
            // start it; the user's previous lifecycle choice (running or
            // stopped) is preserved. process.runtime_unit_stopped is the
            // lifecycle command's concern, not apply's.
            return ProcessDockerContainerApplyOutcome::Unchanged;
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

    private function ensureNetwork(Node $node, ProcessDockerContainer $container): void
    {
        $result = $this->run($node, $this->commands->networkInspect($container->network()));

        if ($result->successful()) {
            return;
        }

        $this->runRequired(
            $node,
            $this->commands->networkCreate($container->network()),
            "create {$container->network()} Docker network",
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspect(Node $node, ProcessDockerContainer $container): ?array
    {
        $result = $this->run($node, $this->commands->containerInspect($container->name()));

        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->stdout);

        if ($output === '') {
            return null;
        }

        $inspection = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($inspection)) {
            throw new RuntimeException("Docker returned an invalid inspect payload for {$container->name()} on {$node->name}.");
        }

        return $inspection;
    }

    private function createContainer(Node $node, ProcessDockerContainer $container): void
    {
        $this->runRequired(
            $node,
            $this->commands->createIdle($container),
            "create {$container->name()} container",
        );
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

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function matchesSpec(array $inspection, ProcessDockerContainer $container): bool
    {
        $labels = $inspection['Config']['Labels'] ?? [];

        if (! is_array($labels)) {
            return false;
        }

        return ($labels[ProcessDockerContainer::SpecHashLabel] ?? null) === $container->specHash();
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

    private function runRequired(Node $node, string $script, string $step): void
    {
        $result = $this->run($node, $script);

        if ($result->successful()) {
            return;
        }

        $output = trim($result->errorOutput().' '.$result->stdout);
        $message = $output !== '' ? $output : 'unknown error';

        throw new RuntimeException("Failed to {$step} on {$node->name}: {$message}");
    }
}
