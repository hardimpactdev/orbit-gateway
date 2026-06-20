<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class OrbitDnsServiceInstaller
{
    public function __construct(
        private readonly DnsmasqConfigBuilder $configBuilder,
        private readonly string $rootPath,
    ) {}

    public function install(): void
    {
        $this->ensureWgEasyRunning();

        File::ensureDirectoryExists($this->rootPath);

        $confPath = $this->rootPath.'/dnsmasq.conf';
        $config = $this->configBuilder->buildGatewayState();
        $existingConfig = File::exists($confPath) ? File::get($confPath) : null;

        if ($existingConfig !== $config) {
            File::put($confPath, $config);
        }

        $composePath = $this->rootPath.'/docker-compose.yaml';
        $compose = $this->renderCompose($confPath);
        $existingCompose = File::exists($composePath) ? File::get($composePath) : null;

        if ($existingCompose !== $compose) {
            File::put($composePath, $compose);
        }

        $result = Process::timeout(180)->run(sprintf(
            'docker compose -f %s up -d',
            escapeshellarg($composePath),
        ));

        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to start orbit-dns: '.trim($result->errorOutput().' '.$result->output())
            );
        }
    }

    private function ensureWgEasyRunning(): void
    {
        $result = Process::timeout(15)->run('docker ps -q -f name=wg-easy');

        if (! $result->successful() || trim($result->output()) === '') {
            throw new RuntimeException(
                'wg-easy container is not running; install wg-easy before orbit-dns.'
            );
        }
    }

    private function renderCompose(string $confPath): string
    {
        return <<<YAML
services:
  orbit-dns:
    image: 4km3/dnsmasq:latest
    container_name: orbit-dns
    network_mode: "container:wg-easy"
    restart: unless-stopped
    cap_add:
      - NET_ADMIN
    volumes:
      - {$confPath}:/etc/dnsmasq.conf:ro

YAML;
    }
}
