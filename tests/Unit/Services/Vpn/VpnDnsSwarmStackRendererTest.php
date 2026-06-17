<?php

declare(strict_types=1);

use App\Services\Vpn\VpnDnsSwarmStackRenderer;

it('renders vpn and dns as separate co-located Swarm services on a shared network', function (): void {
    $yaml = (new VpnDnsSwarmStackRenderer)->render(
        publicHost: '203.0.113.10',
        username: 'orbit',
        password: 'secret-password',
        dnsmasqImage: '4km3/dnsmasq:2.90-r3-alpine-latest',
    );

    expect($yaml)
        ->toContain('orbit-vpn:')
        ->toContain('image: "ghcr.io/wg-easy/wg-easy:15"')
        ->toContain('orbit-dns:')
        ->toContain('image: "4km3/dnsmasq:2.90-r3-alpine-latest"')
        ->toContain('orbit-network:')
        ->toContain('external: true')
        ->toContain('aliases:')
        ->toContain('- wg-easy')
        ->toContain('- orbit-dns')
        ->toContain('INIT_HOST: 203.0.113.10')
        ->toContain('INIT_USERNAME: orbit')
        ->toContain('INIT_PASSWORD: secret-password')
        ->toContain('INIT_DNS: 10.6.0.1')
        ->toContain('target: 51820')
        ->toContain('published: 51820')
        ->toContain('protocol: udp')
        ->toContain('mode: host')
        ->toContain('/dev/net/tun:/dev/net/tun')
        ->toContain('NET_ADMIN')
        ->toContain('SYS_MODULE')
        ->toContain('/home/orbit/.config/orbit/wg-easy:/etc/wireguard')
        ->toContain('${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}/dnsmasq.conf:/etc/dnsmasq.conf:ro')
        ->not->toContain('devices:');

    $vpnBlock = substr($yaml, strpos($yaml, '  orbit-vpn:'), strpos($yaml, '  orbit-dns:') - strpos($yaml, '  orbit-vpn:'));
    $dnsBlock = substr($yaml, strpos($yaml, '  orbit-dns:'), strrpos($yaml, 'networks:') - strpos($yaml, '  orbit-dns:'));

    expect($vpnBlock)
        ->toContain('node.labels.orbit.role.gateway == true')
        ->toContain('node.labels.orbit.role.vpn == true')
        ->not->toContain('4km3/dnsmasq')
        ->and($dnsBlock)
        ->toContain('node.labels.orbit.role.gateway == true')
        ->toContain('node.labels.orbit.role.vpn == true')
        ->toContain('node.labels.orbit.role.dns == true')
        ->not->toContain('ports:')
        ->not->toContain('network_mode:')
        ->not->toContain('wg-easy');
});

it('can mount wg-easy state from the configured state path', function (): void {
    $yaml = (new VpnDnsSwarmStackRenderer)->render(
        publicHost: '203.0.113.10',
        username: 'orbit',
        password: 'secret-password',
        configRoot: '/home/orbit/.config/orbit',
        statePath: '/home/orbit/.wg-easy',
    );

    expect($yaml)
        ->toContain('/home/orbit/.wg-easy:/etc/wireguard')
        ->not->toContain('/home/orbit/.config/orbit/wg-easy:/etc/wireguard');
});

it('rejects latest image tags for the Swarm runtime services', function (): void {
    expect(fn (): string => (new VpnDnsSwarmStackRenderer)->render(
        publicHost: '203.0.113.10',
        username: 'orbit',
        password: 'secret-password',
        vpnImage: 'ghcr.io/wg-easy/wg-easy:latest',
        dnsmasqImage: '4km3/dnsmasq:2.90-r3-alpine-latest',
    ))->toThrow(InvalidArgumentException::class, 'must be pinned');

    expect(fn (): string => (new VpnDnsSwarmStackRenderer)->render(
        publicHost: '203.0.113.10',
        username: 'orbit',
        password: 'secret-password',
        dnsmasqImage: '4km3/dnsmasq:latest',
    ))->toThrow(InvalidArgumentException::class, 'must be pinned');
});

it('renders a vpn-side dns forwarding script from wg0 to the dns service', function (): void {
    $script = (new VpnDnsSwarmStackRenderer)->renderDnsForwardingScript();

    expect($script)
        ->toContain("getent hosts 'orbit-dns'")
        ->toContain('iptables -t nat')
        ->toContain('PREROUTING')
        ->toContain('-i wg0')
        ->toContain('-p udp')
        ->toContain('-p tcp')
        ->toContain('--dport 53')
        ->toContain('DNAT')
        ->toContain('MASQUERADE')
        ->not->toContain('docker restart wg-easy')
        ->not->toContain('docker restart orbit-vpn');
});

it('rejects unsafe WireGuard interface names in forwarding scripts', function (): void {
    expect(fn (): string => (new VpnDnsSwarmStackRenderer)->renderDnsForwardingScript(
        wireguardInterface: 'wg0; reboot',
    ))->toThrow(InvalidArgumentException::class, 'unsupported characters');
});
