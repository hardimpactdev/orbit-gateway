<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Runtime\DockerCommandBuilder;
use RuntimeException;

final readonly class WebSocketRuntimeContainerManager
{
    public function __construct(
        private RemoteShell $remoteShell,
        private DockerCommandBuilder $commands,
    ) {}

    /**
     * Converges the WebSocket Reverb runtime through host Docker commands.
     * This is RemoteHostExecutor lane work: it manages Docker substrate while
     * the application command runs inside the websocket runtime container.
     *
     * @see apps/docs/content/execution-lanes.md
     */
    public function apply(Node $node, WebSocketRuntimeContainer $container): void
    {
        $this->ensureNetwork($node, $container);

        $inspection = $this->inspect($node, $container);

        if ($inspection === null) {
            $this->createContainer($node, $container);

            return;
        }

        if (! $this->matchesSpec($inspection, $container)) {
            $this->runRequired(
                $node,
                $this->commands->containerRemove($container->name()),
                "remove drifted {$container->name()} container",
                'websocket-runtime-container-remove',
            );

            $this->createContainer($node, $container);

            return;
        }

        if (! $this->isRunning($inspection)) {
            $this->runRequired(
                $node,
                $this->commands->containerStart($container->name()),
                "start {$container->name()} container",
                'websocket-runtime-container-start',
            );
        }
    }

    public function remove(Node $node, string $containerName): bool
    {
        $inspect = $this->run($node, $this->commands->containerInspect($containerName), 'websocket-runtime-container-inspect');

        if ($inspect->successful() && trim($inspect->stdout) !== '') {
            return $this->run($node, $this->commands->containerRemove($containerName), 'websocket-runtime-container-remove')->successful();
        }

        return $this->isDockerNoSuchObject($inspect);
    }

    private function ensureNetwork(Node $node, WebSocketRuntimeContainer $container): void
    {
        $result = $this->run($node, $this->commands->networkInspect($container->network()), 'websocket-runtime-network-inspect');

        if ($result->successful()) {
            return;
        }

        $this->runRequired(
            $node,
            $this->commands->networkCreate($container->network()),
            "create {$container->network()} Docker network",
            'websocket-runtime-network-create',
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspect(Node $node, WebSocketRuntimeContainer $container): ?array
    {
        $result = $this->run($node, $this->commands->containerInspect($container->name()), 'websocket-runtime-container-inspect');

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

    private function createContainer(Node $node, WebSocketRuntimeContainer $container): void
    {
        $this->runRequired(
            $node,
            $this->commands->runDetached($container),
            "create {$container->name()} container",
            'websocket-runtime-container-create',
        );
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function matchesSpec(array $inspection, WebSocketRuntimeContainer $container): bool
    {
        $labels = $inspection['Config']['Labels'] ?? [];

        if (! is_array($labels)) {
            return false;
        }

        return ($labels[WebSocketRuntimeContainer::SpecHashLabel] ?? null) === $container->specHash();
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function isRunning(array $inspection): bool
    {
        return ($inspection['State']['Running'] ?? false) === true;
    }

    private function isDockerNoSuchObject(RemoteShellResult $result): bool
    {
        $message = $result->stderr.' '.$result->stdout;

        return preg_match('/No such (object|container)/i', $message) === 1;
    }

    private function run(Node $node, string $script, string $operation): RemoteShellResult
    {
        return $this->remoteShell->run($node, $script, [
            'metadata' => [
                'ORBIT_OPERATION_ID' => $operation,
            ],
        ]);
    }

    private function runRequired(Node $node, string $script, string $step, string $operation): void
    {
        $result = $this->run($node, $script, $operation);

        if ($result->successful()) {
            return;
        }

        $output = trim($result->errorOutput().' '.$result->stdout);
        $message = $output !== '' ? $output : 'unknown error';

        throw new RuntimeException("Failed to {$step} on {$node->name}: {$message}");
    }
}
