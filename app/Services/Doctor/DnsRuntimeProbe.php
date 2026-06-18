<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Services\Dns\DnsmasqConfigBuilder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

final readonly class DnsRuntimeProbe
{
    public function __construct(
        private DnsmasqConfigBuilder $configBuilder,
        private string $rootPath,
    ) {}

    public function family(): string
    {
        return 'tool';
    }

    /**
     * @return list<DriftEntry>
     */
    public function probe(): array
    {
        $drift = [];

        if (! $this->containerExists()) {
            $drift[] = new DriftEntry(
                family: $this->family(),
                key: 'dns.container_missing',
                kind: DriftKind::Missing,
                summary: 'orbit-dns container is not present.',
            );

            return $drift;
        }

        if (! $this->portListening()) {
            $drift[] = new DriftEntry(
                family: $this->family(),
                key: 'dns.port_not_listening',
                kind: DriftKind::Divergent,
                summary: 'orbit-dns is not listening on port 53 inside the wg-easy network namespace.',
            );
        }

        if ($this->configDrifted()) {
            $drift[] = new DriftEntry(
                family: $this->family(),
                key: 'dns.config_drift',
                kind: DriftKind::Divergent,
                summary: 'orbit-dns dnsmasq.conf differs from the gateway intent.',
                detail: ['path' => $this->confPath()],
            );
        }

        return $drift;
    }

    public function isRestorable(string $driftKey): bool
    {
        return in_array($driftKey, [
            'dns.container_missing',
            'dns.port_not_listening',
            'dns.config_drift',
        ], true);
    }

    public function isAdoptable(string $driftKey): bool
    {
        return $driftKey === 'dns.config_drift';
    }

    public function restore(string $driftKey): bool
    {
        return match ($driftKey) {
            'dns.container_missing' => $this->restoreContainer(),
            'dns.port_not_listening' => $this->restartContainer(),
            'dns.config_drift' => $this->restoreConfig(),
            default => false,
        };
    }

    public function adopt(string $driftKey): bool
    {
        if ($driftKey !== 'dns.config_drift') {
            return false;
        }

        return File::exists($this->confPath());
    }

    private function containerExists(): bool
    {
        $result = Process::timeout(15)->run('docker ps -a -q -f name=orbit-dns');

        return $result->successful() && trim($result->output()) !== '';
    }

    private function portListening(): bool
    {
        $result = Process::timeout(15)->run('docker exec orbit-dns sh -c "netstat -lnu 2>/dev/null | grep :53 || ss -lnu 2>/dev/null | grep :53"');

        return $result->successful() && trim($result->output()) !== '';
    }

    private function configDrifted(): bool
    {
        $confPath = $this->confPath();

        if (! File::exists($confPath)) {
            return true;
        }

        $expected = $this->configBuilder->buildGatewayState();

        return File::get($confPath) !== $expected;
    }

    private function restoreContainer(): bool
    {
        $result = Process::timeout(180)->run(sprintf(
            'docker compose -f %s up -d',
            escapeshellarg($this->composePath()),
        ));

        return $result->successful();
    }

    private function restartContainer(): bool
    {
        $result = Process::timeout(30)->run('docker restart orbit-dns');

        return $result->successful();
    }

    private function restoreConfig(): bool
    {
        $expected = $this->configBuilder->buildGatewayState();
        File::put($this->confPath(), $expected);
        $result = Process::timeout(30)->run('docker restart orbit-dns');

        return $result->successful();
    }

    private function confPath(): string
    {
        return $this->rootPath.'/dnsmasq.conf';
    }

    private function composePath(): string
    {
        return $this->rootPath.'/docker-compose.yaml';
    }
}
