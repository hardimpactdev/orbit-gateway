<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeDrivers;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessDockerContainer;
use App\Services\Processes\ProcessDockerContainerRenderer;
use App\Services\Processes\ProcessDockerRuntimeManager;
use Throwable;

final readonly class DockerProcessRuntimeDriver implements ProcessRuntimeDriver
{
    public function __construct(
        private RemoteShell $remoteShell,
        private ProcessDockerContainerRenderer $renderer,
        private ProcessDockerRuntimeManager $manager,
    ) {}

    public function runtimeUnitName(App $app, Process $process, ?Workspace $workspace = null): string
    {
        return $this->renderer->containerName($app, $process, $workspace);
    }

    public function apply(Node $node, App $app, Process $process, ?Workspace $workspace = null, ?string $preApplyScript = null): bool
    {
        try {
            if ($preApplyScript !== null && trim($preApplyScript) !== '') {
                $this->remoteShell->run($node, $preApplyScript);
            }

            $container = $this->renderer->render($app, $process, $workspace);

            if ($process->owner instanceof Node) {
                if (! $this->pullImage($node, $container->image())) {
                    return false;
                }

                if (! $this->prepareMountSources($node, $container)) {
                    return false;
                }
            }

            $this->manager->apply($node, $container);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function remove(Node $node, string $runtimeUnit): bool
    {
        return $this->manager->remove($node, $runtimeUnit);
    }

    public function cleanupScript(string $runtimeUnit): string
    {
        return sprintf('docker rm -f %s 2>/dev/null || true', escapeshellarg($runtimeUnit));
    }

    public function start(Node $node, string $runtimeUnit): bool
    {
        return $this->manager->start($node, $runtimeUnit);
    }

    public function stop(Node $node, string $runtimeUnit): bool
    {
        return $this->manager->stop($node, $runtimeUnit);
    }

    public function restart(Node $node, string $runtimeUnit): bool
    {
        return $this->manager->restart($node, $runtimeUnit);
    }

    private function pullImage(Node $node, string $image): bool
    {
        return $this->remoteShell->run($node, 'docker pull '.escapeshellarg($image))->successful();
    }

    private function prepareMountSources(Node $node, ProcessDockerContainer $container): bool
    {
        $sources = array_values(array_unique(array_map(
            static fn (array $mount): string => $mount['source'],
            $container->mounts(),
        )));

        if ($sources === []) {
            return true;
        }

        $script = 'sudo mkdir -p '.implode(' ', array_map(escapeshellarg(...), $sources));

        return $this->remoteShell->run($node, $script)->successful();
    }

    public function logScript(App $app, Process $process, ?Workspace $workspace, string $runtimeUnit, int $lines, bool $follow): string
    {
        return collect([
            'docker logs',
            "--tail {$lines}",
            $follow ? '--follow' : null,
            escapeshellarg($runtimeUnit),
            '2>&1',
        ])->filter()->implode(' ');
    }
}
