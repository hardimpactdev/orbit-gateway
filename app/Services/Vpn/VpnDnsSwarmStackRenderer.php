<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use App\Services\Gateway\GatewaySwarmStackRenderer;
use InvalidArgumentException;

final readonly class VpnDnsSwarmStackRenderer
{
    public const string VpnService = 'orbit-vpn';

    public const string DnsService = 'orbit-dns';

    public const string DefaultDnsmasqImage = '4km3/dnsmasq:2.90-r3-alpine-latest';

    public function render(
        string $publicHost,
        string $username,
        string $password,
        string $wireguardCidr = '10.6.0.0/24',
        int $wireguardPort = 51820,
        string $dnsIp = '10.6.0.1',
        string $vpnImage = WgEasyServiceInstaller::Image,
        string $dnsmasqImage = self::DefaultDnsmasqImage,
        string $configRoot = '/home/orbit/.config/orbit',
        ?string $statePath = null,
        string $network = GatewaySwarmStackRenderer::Network,
    ): string {
        $this->assertFilled($publicHost, 'public host');
        $this->assertFilled($username, 'wg-easy username');
        $this->assertFilled($password, 'wg-easy password');
        $this->assertPinnedImage($vpnImage, 'VPN image');
        $this->assertPinnedImage($dnsmasqImage, 'DNS image');

        $configRoot = $this->normalizePath($configRoot, 'config root');
        $configRootExpression = '${ORBIT_CONFIG_ROOT:-'.$configRoot.'}';
        $statePath = $this->normalizePath($statePath ?? $configRoot.'/wg-easy', 'wg-easy state path');

        return implode("\n", [
            'version: "3.8"',
            'services:',
            ...$this->vpnService(
                publicHost: $publicHost,
                username: $username,
                password: $password,
                wireguardCidr: $wireguardCidr,
                wireguardPort: $wireguardPort,
                dnsIp: $dnsIp,
                vpnImage: $vpnImage,
                configRoot: $configRoot,
                statePath: $statePath,
                network: $network,
            ),
            ...$this->dnsService(
                dnsmasqImage: $dnsmasqImage,
                configRoot: $configRoot,
                configRootExpression: $configRootExpression,
                network: $network,
            ),
            'networks:',
            '  '.$network.':',
            '    external: true',
            '',
        ]);
    }

    public function renderDnsForwardingScript(string $dnsService = self::DnsService, string $wireguardInterface = 'wg0'): string
    {
        $this->assertFilled($dnsService, 'DNS service');
        $this->assertLinuxInterface($wireguardInterface);

        return sprintf(
            <<<'SH'
set -eu

dns_ip="$(getent hosts %s | awk '{ print $1; exit }')"

if [ -z "$dns_ip" ]; then
    echo "Unable to resolve %s on the shared Swarm network" >&2
    exit 1
fi

iptables -t nat -C PREROUTING -i %s -p udp --dport 53 -j DNAT --to-destination "${dns_ip}:53" 2>/dev/null \
    || iptables -t nat -A PREROUTING -i %s -p udp --dport 53 -j DNAT --to-destination "${dns_ip}:53"
iptables -t nat -C PREROUTING -i %s -p tcp --dport 53 -j DNAT --to-destination "${dns_ip}:53" 2>/dev/null \
    || iptables -t nat -A PREROUTING -i %s -p tcp --dport 53 -j DNAT --to-destination "${dns_ip}:53"

iptables -t nat -C POSTROUTING -p udp -d "$dns_ip" --dport 53 -j MASQUERADE 2>/dev/null \
    || iptables -t nat -A POSTROUTING -p udp -d "$dns_ip" --dport 53 -j MASQUERADE
iptables -t nat -C POSTROUTING -p tcp -d "$dns_ip" --dport 53 -j MASQUERADE 2>/dev/null \
    || iptables -t nat -A POSTROUTING -p tcp -d "$dns_ip" --dport 53 -j MASQUERADE
SH,
            escapeshellarg($dnsService),
            $dnsService,
            $wireguardInterface,
            $wireguardInterface,
            $wireguardInterface,
            $wireguardInterface,
        );
    }

    /**
     * @return list<string>
     */
    private function vpnService(
        string $publicHost,
        string $username,
        string $password,
        string $wireguardCidr,
        int $wireguardPort,
        string $dnsIp,
        string $vpnImage,
        string $configRoot,
        string $statePath,
        string $network,
    ): array {
        return [
            '  '.self::VpnService.':',
            '    image: '.$this->quoted($vpnImage),
            '    networks:',
            '      '.$network.':',
            '        aliases:',
            '          - wg-easy',
            '          - '.self::VpnService,
            '    environment:',
            '      INIT_ENABLED: "true"',
            '      INIT_USERNAME: '.$username,
            '      INIT_PASSWORD: '.$password,
            '      INIT_HOST: '.$publicHost,
            '      INIT_PORT: "'.$wireguardPort.'"',
            '      INIT_DNS: '.$dnsIp,
            '      INIT_ALLOWED_IPS: '.$wireguardCidr,
            '      INSECURE: "true"',
            '      DISABLE_IPV6: "true"',
            '    ports:',
            '      - target: '.$wireguardPort,
            '        published: '.$wireguardPort,
            '        protocol: udp',
            '        mode: host',
            '    cap_add:',
            '      - NET_ADMIN',
            '      - SYS_MODULE',
            '    volumes:',
            '      - /dev/net/tun:/dev/net/tun',
            '      - '.$statePath.':/etc/wireguard',
            '      - /lib/modules:/lib/modules:ro',
            '    deploy:',
            '      replicas: 1',
            '      labels:',
            '        orbit.managed: "true"',
            '        orbit.service: '.self::VpnService,
            '      placement:',
            '        constraints:',
            '          - node.labels.orbit.role.gateway == true',
            '          - node.labels.orbit.role.vpn == true',
            '      update_config:',
            '        parallelism: 1',
            '        order: stop-first',
            '        failure_action: rollback',
            '    x-orbit-config-root: '.$configRoot,
        ];
    }

    /**
     * @return list<string>
     */
    private function dnsService(
        string $dnsmasqImage,
        string $configRoot,
        string $configRootExpression,
        string $network,
    ): array {
        return [
            '  '.self::DnsService.':',
            '    image: '.$this->quoted($dnsmasqImage),
            '    networks:',
            '      '.$network.':',
            '        aliases:',
            '          - '.self::DnsService,
            '    cap_add:',
            '      - NET_ADMIN',
            '    volumes:',
            '      - '.$configRootExpression.'/dnsmasq.conf:/etc/dnsmasq.conf:ro',
            '    deploy:',
            '      replicas: 1',
            '      labels:',
            '        orbit.managed: "true"',
            '        orbit.service: '.self::DnsService,
            '      placement:',
            '        constraints:',
            '          - node.labels.orbit.role.gateway == true',
            '          - node.labels.orbit.role.vpn == true',
            '          - node.labels.orbit.role.dns == true',
            '      update_config:',
            '        parallelism: 1',
            '        order: stop-first',
            '        failure_action: rollback',
            '    x-orbit-config-root: '.$configRoot,
        ];
    }

    private function assertPinnedImage(string $image, string $label): void
    {
        $this->assertFilled($image, $label);

        if (str_contains($image, '@sha256:')) {
            return;
        }

        $lastSegment = basename($image);

        if (! str_contains($lastSegment, ':') || str_ends_with($lastSegment, ':latest')) {
            throw new InvalidArgumentException("{$label} must be pinned and must not use :latest.");
        }
    }

    private function assertFilled(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("VPN/DNS Swarm {$label} cannot be empty.");
        }
    }

    private function assertLinuxInterface(string $value): void
    {
        $this->assertFilled($value, 'WireGuard interface');

        if (! preg_match('/^[a-zA-Z0-9_.:-]+$/', $value)) {
            throw new InvalidArgumentException('VPN/DNS Swarm WireGuard interface contains unsupported characters.');
        }
    }

    private function normalizePath(string $path, string $label): string
    {
        $this->assertFilled($path, $label);

        if ($path === '/') {
            return $path;
        }

        return rtrim($path, '/');
    }

    private function quoted(string $value): string
    {
        return '"'.str_replace('"', '\"', $value).'"';
    }
}
