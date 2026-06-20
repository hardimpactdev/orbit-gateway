<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Services\Vpn\VpnDnsSwarmManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class DnsmasqReconciler
{
    public function __construct(
        private readonly DnsmasqConfigBuilder $configBuilder,
        private readonly string $rootPath,
        private readonly ?VpnDnsSwarmManager $swarmManager = null,
    ) {}

    public function reconcile(): void
    {
        File::ensureDirectoryExists($this->rootPath);

        $confPath = $this->rootPath.'/dnsmasq.conf';
        $expected = $this->configBuilder->buildGatewayState();
        $current = File::exists($confPath) ? File::get($confPath) : null;

        if ($current === $expected) {
            return;
        }

        File::put($confPath, $expected);

        if ($this->swarmManager()->restartDnsServiceIfPresent() === true) {
            return;
        }

        Process::timeout(30)->run('docker restart orbit-dns');
    }

    private function swarmManager(): VpnDnsSwarmManager
    {
        return $this->swarmManager ?? app(VpnDnsSwarmManager::class);
    }
}
