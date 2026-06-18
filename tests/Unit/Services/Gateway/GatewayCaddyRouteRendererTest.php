<?php

declare(strict_types=1);

use App\Services\Gateway\GatewayCaddyRouteRenderer;

it('renders the router-colocated gateway Caddy route', function (): void {
    $route = (new GatewayCaddyRouteRenderer)->render(
        serverNames: ['10.6.0.1', 'gateway.orbit.test'],
        wireguardCidr: '10.6.0.0/24',
    );

    expect($route)
        ->toContain('10.6.0.1 gateway.orbit.test {')
        ->toContain('tls /run/orbit-gateway-certs/gateway.crt /run/orbit-gateway-certs/gateway.key')
        ->toContain('@notWireGuard')
        ->toContain('remote_ip 10.6.0.0/24')
        ->not->toContain('client_ip')
        ->toContain('abort @notWireGuard')
        ->toContain('request_header -X-Forwarded-For')
        ->toContain('request_header -X-Real-IP')
        ->toContain('request_header -Forwarded')
        ->toContain('request_header -X-Orbit-WireGuard-Ip')
        ->toContain('reverse_proxy http://orbit-gateway:8080')
        ->toContain('flush_interval -1')
        ->toContain('header_up X-Orbit-WireGuard-Ip {remote_host}')
        ->toContain('header_up X-Forwarded-Proto https')
        ->not->toContain('encode gzip');
});

it('rejects an empty server name list', function (): void {
    expect(fn () => (new GatewayCaddyRouteRenderer)->render([], '10.6.0.0/24'))
        ->toThrow(InvalidArgumentException::class, 'Gateway Caddy route requires at least one server name.');
});
