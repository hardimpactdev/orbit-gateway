<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeDrivers;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\SystemdUnitRenderer;

final readonly class SystemdProcessRuntimeDriver implements ProcessRuntimeDriver
{
    public function __construct(
        private RemoteShell $remoteShell,
        private SystemdUnitRenderer $renderer,
    ) {}

    public function runtimeUnitName(App $app, Process $process, ?Workspace $workspace = null): string
    {
        return $this->renderer->unitName($app, $process, $workspace);
    }

    public function apply(Node $node, App $app, Process $process, ?Workspace $workspace = null, ?string $preApplyScript = null): bool
    {
        $script = collect([
            'set -euo pipefail',
            $preApplyScript,
            $this->renderer->installScript($node, $app, $process, $workspace),
        ])->filter(fn (?string $script): bool => $script !== null && trim($script) !== '')->implode(PHP_EOL);

        return $this->remoteShell->run($node, $script)->successful();
    }

    public function remove(Node $node, string $runtimeUnit): bool
    {
        return $this->remoteShell->run($node, $this->removeScript($runtimeUnit))->successful();
    }

    public function cleanupScript(string $runtimeUnit): string
    {
        $serviceName = $this->renderer->serviceName($runtimeUnit);

        return sprintf(
            'sudo systemctl stop %1$s >/dev/null 2>&1 || true; sudo systemctl disable %1$s >/dev/null 2>&1 || true; sudo rm -f %2$s; sudo systemctl daemon-reload; sudo systemctl reset-failed %1$s >/dev/null 2>&1 || true; true',
            escapeshellarg($serviceName),
            escapeshellarg($this->renderer->unitPath($runtimeUnit)),
        );
    }

    public function start(Node $node, string $runtimeUnit): bool
    {
        return $this->remoteShell->run($node, 'sudo systemctl start '.escapeshellarg($this->renderer->serviceName($runtimeUnit)))->successful();
    }

    public function stop(Node $node, string $runtimeUnit): bool
    {
        return $this->remoteShell->run($node, 'sudo systemctl stop '.escapeshellarg($this->renderer->serviceName($runtimeUnit)))->successful();
    }

    public function restart(Node $node, string $runtimeUnit): bool
    {
        return $this->remoteShell->run($node, 'sudo systemctl restart '.escapeshellarg($this->renderer->serviceName($runtimeUnit)))->successful();
    }

    public function logScript(App $app, Process $process, ?Workspace $workspace, string $runtimeUnit, int $lines, bool $follow): string
    {
        return collect([
            'sudo journalctl',
            '-u',
            escapeshellarg($this->renderer->serviceName($runtimeUnit)),
            "-n {$lines}",
            $follow ? '-f' : null,
            '--no-pager',
            '--output=short-iso',
            '2>&1',
        ])->filter()->implode(' ');
    }

    private function removeScript(string $runtimeUnit): string
    {
        $serviceName = $this->renderer->serviceName($runtimeUnit);
        $unitPath = $this->renderer->unitPath($runtimeUnit);

        return sprintf(
            <<<'SH'
sudo systemctl stop %1$s >/dev/null 2>&1 || true
sudo systemctl disable %1$s >/dev/null 2>&1 || true
sudo rm -f %2$s
sudo systemctl daemon-reload
sudo systemctl reset-failed %1$s >/dev/null 2>&1 || true
SH,
            escapeshellarg($serviceName),
            escapeshellarg($unitPath),
        );
    }
}
