<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class VpnDnsSwarmManager
{
    public function __construct(
        private VpnDnsSwarmStackRenderer $renderer = new VpnDnsSwarmStackRenderer,
    ) {}

    public function convergeDnsForwarding(string $stack = 'orbit'): void
    {
        $containerId = $this->vpnTaskContainer($stack);
        $script = $this->renderer->renderDnsForwardingScript();
        $result = Process::timeout(30)->run(
            'docker exec '.escapeshellarg($containerId).' sh -lc '.escapeshellarg($script),
        );

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException('Failed to converge VPN/DNS forwarding: '.trim($result->errorOutput().' '.$result->output()));
    }

    public function restartDnsService(string $stack = 'orbit'): void
    {
        $dnsService = $this->stackService($stack, VpnDnsSwarmStackRenderer::DnsService);
        $result = Process::timeout(60)->run('docker service update --force '.escapeshellarg($dnsService));

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException("Failed to restart DNS Swarm service [{$dnsService}]: ".trim($result->errorOutput().' '.$result->output()));
    }

    public function restartDnsServiceIfPresent(string $stack = 'orbit'): bool
    {
        $dnsService = $this->stackService($stack, VpnDnsSwarmStackRenderer::DnsService);
        $inspect = Process::timeout(15)->run('docker service inspect '.escapeshellarg($dnsService));

        if (! $inspect->successful()) {
            return false;
        }

        $this->restartDnsService($stack);

        return true;
    }

    public function vpnTaskContainer(string $stack = 'orbit'): string
    {
        $vpnService = $this->stackService($stack, VpnDnsSwarmStackRenderer::VpnService);
        $containerId = $this->firstVpnTaskContainer($vpnService);

        if ($containerId === null) {
            throw new RuntimeException("VPN Swarm task container for [{$vpnService}] is not running.");
        }

        return $containerId;
    }

    private function firstVpnTaskContainer(string $vpnService): ?string
    {
        $result = Process::timeout(15)->run(
            'docker ps -q --filter '.escapeshellarg("label=com.docker.swarm.service.name={$vpnService}"),
        );

        if (! $result->successful()) {
            throw new RuntimeException("Failed to inspect VPN Swarm task container for [{$vpnService}]: ".trim($result->errorOutput().' '.$result->output()));
        }

        $containerIds = array_values(array_filter(explode("\n", trim($result->output()))));

        return $containerIds[0] ?? null;
    }

    private function stackService(string $stack, string $service): string
    {
        $stack = trim($stack);

        if ($stack === '') {
            throw new RuntimeException('VPN/DNS Swarm stack name cannot be empty.');
        }

        return "{$stack}_{$service}";
    }
}
