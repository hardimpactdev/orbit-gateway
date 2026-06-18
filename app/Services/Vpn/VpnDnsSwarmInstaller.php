<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use App\Services\Dns\DnsmasqConfigBuilder;
use App\Services\Gateway\GatewaySwarmManager;
use App\Services\Gateway\GatewaySwarmStackRenderer;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class VpnDnsSwarmInstaller extends WgEasyServiceInstaller
{
    private const string StackFile = 'orbit-vpn-dns-stack.yml';

    public function __construct(
        private readonly string $rootPath,
        private readonly ?string $statePath = null,
        private readonly GatewaySwarmManager $swarm = new GatewaySwarmManager,
        private readonly VpnDnsSwarmStackRenderer $renderer = new VpnDnsSwarmStackRenderer,
        private readonly VpnDnsSwarmManager $manager = new VpnDnsSwarmManager,
        private readonly DnsmasqConfigBuilder $configBuilder = new DnsmasqConfigBuilder,
        ?RemoteLocalExecutor $localExecutor = null,
        ?VpnNodeResolver $vpnNodeResolver = null,
    ) {
        parent::__construct(
            rootPath: $rootPath,
            statePath: $statePath,
            localExecutor: $localExecutor,
            vpnNodeResolver: $vpnNodeResolver,
        );
    }

    #[\Override]
    public function install(
        string $publicHost,
        string $username,
        string $password,
        string $wireguardCidr = '10.6.0.0/24',
        int $wireguardPort = 51820,
        string $dnsIp = '10.6.0.1',
    ): void {
        if ($publicHost === '') {
            throw new RuntimeException('INIT_HOST is required to install the VPN/DNS Swarm runtime.');
        }

        if ($username === '') {
            throw new RuntimeException('A wg-easy admin username is required.');
        }

        if ($password === '') {
            throw new RuntimeException('A wg-easy admin password is required.');
        }

        File::ensureDirectoryExists($this->rootPath, 0700);
        File::ensureDirectoryExists($this->statePath(), 0700);
        File::put($this->rootPath.'/dnsmasq.conf', $this->configBuilder->buildGatewayState());

        $this->swarm->ensureSwarm();
        $this->swarm->ensureGatewayEdgeNodeLabels();
        $this->swarm->ensureAttachableOverlayNetwork(GatewaySwarmStackRenderer::Network);

        $stack = $this->renderer->render(
            publicHost: $publicHost,
            username: $username,
            password: $password,
            wireguardCidr: $wireguardCidr,
            wireguardPort: $wireguardPort,
            dnsIp: $dnsIp,
            configRoot: $this->rootPath,
            statePath: $this->statePath(),
        );

        $stackPath = $this->swarm->writeStackFile($stack, self::StackFile);
        $this->swarm->deployStack($stackPath);

        $this->waitUntilReady();
        $this->ensureStateWritableForHostExecutor();
        $this->ensureWgEasyStateWritable();
        $this->convergeServerAddress($publicHost, $wireguardCidr, $dnsIp);
        $this->manager->convergeDnsForwarding();
    }

    #[\Override]
    public function publicKey(): string
    {
        $this->waitUntilReady();

        $containerId = $this->manager->vpnTaskContainer();
        $result = Process::timeout(30)->run('docker exec '.escapeshellarg($containerId).' wg show wg0 public-key');

        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to read wg-easy WireGuard public key: '.trim($result->errorOutput().' '.$result->output())
            );
        }

        $publicKey = trim($result->output());

        if ($publicKey === '') {
            throw new RuntimeException('wg-easy WireGuard public key is empty.');
        }

        return $publicKey;
    }

    /**
     * @param  list<array{name: string, private_key: string, public_key: string, address: string, pre_shared_key: string}>  $peers
     */
    #[\Override]
    public function configurePeers(array $peers): void
    {
        if ($peers === []) {
            return;
        }

        $this->waitUntilReady();
        $containerId = $this->manager->vpnTaskContainer();
        $runtimeCommands = [];

        foreach ($peers as $peer) {
            $this->deleteWgEasyPeer($peer['name']);
            $this->upsertWgEasyPeer($peer);

            $runtimeCommands[] = sprintf(
                'docker exec %s sh -lc %s',
                escapeshellarg($containerId),
                escapeshellarg(sprintf(
                    'tmp="$(mktemp)" && printf %s %s > "$tmp" && wg set wg0 peer %s preshared-key "$tmp" allowed-ips %s; status="$?"; rm -f "$tmp"; exit "$status"',
                    escapeshellarg('%s\n'),
                    escapeshellarg($peer['pre_shared_key']),
                    escapeshellarg($peer['public_key']),
                    escapeshellarg($peer['address'].'/32'),
                )),
            );
        }

        $result = Process::timeout(120)->run("set -e\n".implode("\n", $runtimeCommands));

        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to configure wg-easy peers: '.trim($result->errorOutput().' '.$result->output())
            );
        }
    }

    private function waitUntilReady(): void
    {
        $result = Process::timeout(75)->run(<<<'SH'
set -eu
for i in $(seq 1 60); do
    container_id="$(docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-vpn' | head -n 1)"

    if [ -n "$container_id" ] && docker exec "$container_id" test -f /etc/wireguard/wg-easy.db && docker exec "$container_id" ip link show wg0 >/dev/null 2>&1; then
        exit 0
    fi

    sleep 1
done

exit 1
SH);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException(
            'wg-easy Swarm service did not become ready: '.trim($result->errorOutput().' '.$result->output())
        );
    }

    private function ensureStateWritableForHostExecutor(): void
    {
        $result = Process::timeout(30)->run(sprintf(
            <<<'SH'
set -e
chmod 0777 %s
chmod 0666 %s
SH,
            escapeshellarg($this->statePath()),
            escapeshellarg($this->statePath().'/wg-easy.db'),
        ));

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException(
            'Failed to make wg-easy Swarm state writable: '.trim($result->errorOutput().' '.$result->output())
        );
    }

    private function convergeServerAddress(string $publicHost, string $wireguardCidr, string $dnsIp): void
    {
        $containerId = $this->manager->vpnTaskContainer();
        $prefix = $this->cidrPrefix($wireguardCidr);
        $serverAddress = "{$dnsIp}/{$prefix}";

        $result = Process::timeout(30)->run(sprintf(
            <<<'SH'
set -e
docker exec %s ip addr replace %s dev wg0
docker exec %s ip route replace %s dev wg0
SH,
            escapeshellarg($containerId),
            escapeshellarg($serverAddress),
            escapeshellarg($containerId),
            escapeshellarg($wireguardCidr),
        ));

        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to converge wg-easy server address: '.trim($result->errorOutput().' '.$result->output())
            );
        }

        $this->updateWgEasyInterface($wireguardCidr);
        $this->updateWgEasyUserConfig($publicHost, '["'.$dnsIp.'"]', 25);
        $this->updateWgEasyGeneralSetupStep(0);
    }

    #[\Override]
    protected function statePath(): string
    {
        return $this->statePath ?? $this->rootPath.'/wg-easy';
    }
}
