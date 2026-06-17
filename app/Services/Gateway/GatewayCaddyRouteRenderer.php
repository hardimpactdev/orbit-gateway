<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use InvalidArgumentException;

final readonly class GatewayCaddyRouteRenderer
{
    public const string CertPath = '/run/orbit-gateway-certs/gateway.crt';

    public const string KeyPath = '/run/orbit-gateway-certs/gateway.key';

    public const string Upstream = 'http://orbit-gateway:8080';

    /**
     * @param  list<string>  $serverNames
     */
    public function render(
        array $serverNames,
        string $wireguardCidr,
        string $certPath = self::CertPath,
        string $keyPath = self::KeyPath,
        string $upstream = self::Upstream,
    ): string {
        $siteAddress = implode(' ', $this->serverNames($serverNames));
        $wireguardCidr = $this->nonEmpty($wireguardCidr, 'wireguard CIDR');
        $certPath = $this->nonEmpty($certPath, 'certificate path');
        $keyPath = $this->nonEmpty($keyPath, 'key path');
        $upstream = $this->nonEmpty($upstream, 'upstream');

        return <<<CADDY
{$siteAddress} {
    tls {$certPath} {$keyPath}

    @notWireGuard {
        not remote_ip {$wireguardCidr}
    }
    abort @notWireGuard

    request_header -X-Forwarded-For
    request_header -X-Real-IP
    request_header -Forwarded
    request_header -X-Orbit-WireGuard-Ip

    reverse_proxy {$upstream} {
        flush_interval -1
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto https
        header_up X-Real-IP {remote_host}
        header_up X-Orbit-WireGuard-Ip {remote_host}
    }
}

CADDY;
    }

    /**
     * @param  list<string>  $serverNames
     * @return list<string>
     */
    private function serverNames(array $serverNames): array
    {
        $names = array_values(array_unique(array_filter(
            array_map(trim(...), $serverNames),
            fn (string $serverName): bool => $serverName !== '',
        )));

        if ($names === []) {
            throw new InvalidArgumentException('Gateway Caddy route requires at least one server name.');
        }

        foreach ($names as $name) {
            if (preg_match('/\s/', $name) === 1) {
                throw new InvalidArgumentException("Gateway Caddy route server name [{$name}] cannot contain whitespace.");
            }
        }

        return $names;
    }

    private function nonEmpty(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("Gateway Caddy route {$field} cannot be empty.");
        }

        return $value;
    }
}
