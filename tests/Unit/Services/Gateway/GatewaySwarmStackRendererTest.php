<?php

declare(strict_types=1);

use App\Enums\Gateway\GatewayExposureMode;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmStackRenderer;

function gatewaySwarmImageForTest(): GatewayImageReference
{
    return GatewayImageReference::fromString('ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
}

it('renders the gateway and scheduler Swarm services for gateway-direct mode', function (): void {
    $yaml = (new GatewaySwarmStackRenderer)->render(
        gatewaySwarmImageForTest(),
        GatewayExposureMode::GatewayDirect,
    );

    expect($yaml)
        ->toContain('version: "3.8"')
        ->toContain('orbit-gateway:')
        ->toContain('image: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"')
        ->toContain('aliases:')
        ->toContain('- orbit-gateway')
        ->toContain('ORBIT_GATEWAY_EXPOSURE_MODE: gateway-direct')
        ->toContain('DB_BUSY_TIMEOUT: "5000"')
        ->toContain('DB_JOURNAL_MODE: wal')
        ->toContain('DB_SYNCHRONOUS: NORMAL')
        ->toContain('ORBIT_FORWARD_INSTALL_BINARY: /usr/local/bin/orbit-cli')
        ->toContain('ORBIT_LOCAL_EXECUTOR_BINARY: /usr/local/bin/orbit-cli')
        ->toContain('ORBIT_GATEWAY_TLS_CERT: /etc/orbit/certs/gateway.crt')
        ->toContain('ORBIT_GATEWAY_TLS_KEY: /etc/orbit/certs/gateway.key')
        ->toContain('ports:')
        ->toContain('target: 443')
        ->toContain('published: 443')
        ->toContain('mode: ingress')
        ->toContain('${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}:/home/orbit/.config/orbit')
        ->toContain('${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}/certs:/etc/orbit/certs:ro')
        ->toContain('${ORBIT_INSTALL_ROOT:-/home/orbit/orbit}/bin/orbit-binary:/usr/local/bin/orbit-cli:ro')
        ->toContain('/var/run/docker.sock:/var/run/docker.sock')
        ->toContain('/home/orbit/.ssh:/root/.ssh:ro')
        ->toContain('healthcheck:')
        ->toContain('test: ["CMD", "orbit-gateway-healthcheck"]')
        ->toContain('orbit.managed: "true"')
        ->toContain('orbit.service: orbit-gateway')
        ->toContain('order: start-first')
        ->toContain('failure_action: rollback')
        ->toContain('monitor: 60s')
        ->toContain('orbit-scheduler:')
        ->toContain('command: ["php", "artisan", "orbit-scheduler"]')
        ->toContain('ORBIT_FORWARD_INSTALL_BINARY: /usr/local/bin/orbit-cli')
        ->toContain('ORBIT_LOCAL_EXECUTOR_BINARY: /usr/local/bin/orbit-cli')
        ->toContain('${ORBIT_INSTALL_ROOT:-/home/orbit/orbit}/bin/orbit-binary:/usr/local/bin/orbit-cli:ro')
        ->toContain('healthcheck:')
        ->toContain('disable: true')
        ->toContain('orbit.service: orbit-scheduler')
        ->toContain('order: stop-first')
        ->toContain('node.labels.orbit.role.gateway == true')
        ->toContain('external: true');
});

it('omits gateway host ports when router-owned Caddy fronts the gateway', function (): void {
    $yaml = (new GatewaySwarmStackRenderer)->render(
        gatewaySwarmImageForTest(),
        GatewayExposureMode::RouterColocated,
    );

    $gatewayBlock = substr($yaml, strpos($yaml, '  orbit-gateway:'), strpos($yaml, '  orbit-scheduler:') - strpos($yaml, '  orbit-gateway:'));

    expect($gatewayBlock)
        ->toContain('ORBIT_GATEWAY_EXPOSURE_MODE: router-colocated')
        ->toContain('ORBIT_TRUST_WIREGUARD_PROXY_HEADER: "1"')
        ->toContain('aliases:')
        ->toContain('- orbit-gateway')
        ->not->toContain('ports:')
        ->and($yaml)
        ->toContain('orbit-scheduler:')
        ->toContain('order: stop-first');
});
