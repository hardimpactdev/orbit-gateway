<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class OrbitGatewayContainerManager
{
    public function __construct(
        private readonly DockerCommandBuilder $commands,
    ) {}

    public function apply(OrbitGatewayContainer $container): void
    {
        $this->ensureNetwork($container);

        $inspection = $this->inspect($container);

        if ($inspection === null) {
            $this->runRequired($this->commands->runDetached($container), "create {$container->name()} container", 120);

            return;
        }

        if (! $this->matchesSpec($inspection, $container)) {
            $this->runRequired($this->commands->containerRemove($container->name()), "remove drifted {$container->name()} container", 60);
            $this->runRequired($this->commands->runDetached($container), "create {$container->name()} container", 120);

            return;
        }

        if (! $this->isRunning($inspection)) {
            $this->runRequired($this->commands->containerStart($container->name()), "start {$container->name()} container", 60);
        }
    }

    public function converge(OrbitGatewayContainer $container): void
    {
        $this->apply($container);
    }

    private function ensureNetwork(OrbitGatewayContainer $container): void
    {
        $result = Process::timeout(30)->run($this->commands->networkInspect($container->network()));

        if ($result->successful()) {
            return;
        }

        $this->runRequired($this->commands->networkCreate($container->network()), "create {$container->network()} Docker network", 60);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspect(OrbitGatewayContainer $container): ?array
    {
        $result = Process::timeout(30)->run($this->commands->containerInspect($container->name()));

        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->output());

        if ($output === '') {
            throw new RuntimeException("Docker returned an empty inspect payload for {$container->name()}.");
        }

        $inspection = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($inspection)) {
            throw new RuntimeException("Docker returned an invalid inspect payload for {$container->name()}.");
        }

        return $inspection;
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function matchesSpec(array $inspection, OrbitGatewayContainer $container): bool
    {
        $labels = $inspection['Config']['Labels'] ?? [];

        if (! is_array($labels)) {
            return false;
        }

        return ($labels[OrbitGatewayContainer::SpecHashLabel] ?? null) === $container->specHash();
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private function isRunning(array $inspection): bool
    {
        return ($inspection['State']['Running'] ?? false) === true;
    }

    private function runRequired(string $command, string $step, int $timeoutSeconds): void
    {
        $result = Process::timeout($timeoutSeconds)->run($command);

        if ($result->successful()) {
            return;
        }

        $output = trim($result->errorOutput().' '.$result->output());
        $message = $output !== '' ? $output : 'unknown error';

        throw new RuntimeException("Failed to {$step}: {$message}");
    }
}
