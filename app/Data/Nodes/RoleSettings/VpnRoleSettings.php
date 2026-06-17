<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class VpnRoleSettings implements NodeRoleSettings
{
    public ?string $publicEndpoint;

    public string $wireguardCidr;

    public int $wireguardPort;

    public string $dnsIp;

    public function __construct(
        ?string $publicEndpoint,
        string $wireguardCidr,
        int $wireguardPort,
        string $dnsIp,
    ) {
        $publicEndpoint = $publicEndpoint === null ? null : trim($publicEndpoint);
        $wireguardCidr = trim($wireguardCidr);
        $dnsIp = trim($dnsIp);

        if ($publicEndpoint !== null && ! self::isValidPublicEndpoint($publicEndpoint)) {
            throw new InvalidArgumentException('The vpn role requires a valid public endpoint setting.');
        }

        if (! self::isValidIpv4Cidr($wireguardCidr)) {
            throw new InvalidArgumentException('The vpn role requires a valid IPv4 CIDR setting.');
        }

        if ($wireguardPort < 1 || $wireguardPort > 65535) {
            throw new InvalidArgumentException('The vpn role requires a valid WireGuard port.');
        }

        if (filter_var($dnsIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('The vpn role requires a valid DNS IP setting.');
        }

        $this->publicEndpoint = $publicEndpoint;
        $this->wireguardCidr = $wireguardCidr;
        $this->wireguardPort = $wireguardPort;
        $this->dnsIp = $dnsIp;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $allowed = ['public_endpoint', 'wireguard_cidr', 'wireguard_port', 'dns_ip'];

        foreach (array_keys($settings) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('The vpn role does not accept unknown settings.');
            }
        }

        $publicEndpoint = is_string($settings['public_endpoint'] ?? null)
            ? trim($settings['public_endpoint'])
            : ($settings['public_endpoint'] ?? null);
        $wireguardCidr = is_string($settings['wireguard_cidr'] ?? null)
            ? trim($settings['wireguard_cidr'])
            : ($settings['wireguard_cidr'] ?? '10.6.0.0/24');
        $wireguardPort = $settings['wireguard_port'] ?? 51820;
        $dnsIp = is_string($settings['dns_ip'] ?? null)
            ? trim($settings['dns_ip'])
            : ($settings['dns_ip'] ?? '10.6.0.1');

        if ($publicEndpoint !== null && ! is_string($publicEndpoint)) {
            throw new InvalidArgumentException('The vpn role requires a valid public endpoint setting.');
        }

        if (! is_string($wireguardCidr)) {
            throw new InvalidArgumentException('The vpn role requires a valid IPv4 CIDR setting.');
        }

        if (! is_int($wireguardPort) || $wireguardPort < 1 || $wireguardPort > 65535) {
            throw new InvalidArgumentException('The vpn role requires a valid WireGuard port.');
        }

        if (! is_string($dnsIp)) {
            throw new InvalidArgumentException('The vpn role requires a valid DNS IP setting.');
        }

        return new self(
            publicEndpoint: $publicEndpoint,
            wireguardCidr: $wireguardCidr,
            wireguardPort: $wireguardPort,
            dnsIp: $dnsIp,
        );
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'public_endpoint' => $this->publicEndpoint,
            'wireguard_cidr' => $this->wireguardCidr,
            'wireguard_port' => $this->wireguardPort,
            'dns_ip' => $this->dnsIp,
        ];
    }

    private static function isValidIpv4Cidr(string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$address, $prefix] = $parts;

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        if (! preg_match('/^(?:[1-9]|[12]\d|3[0-2])$/', $prefix)) {
            return false;
        }

        $prefixLength = (int) $prefix;

        return $prefixLength >= 1 && $prefixLength <= 32;
    }

    private static function isValidPublicEndpoint(string $endpoint): bool
    {
        if ($endpoint === '') {
            return false;
        }

        if (filter_var($endpoint, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! str_contains($endpoint, '.')) {
            return false;
        }

        if (strlen($endpoint) > 253 || str_contains($endpoint, '..')) {
            return false;
        }

        $labels = explode('.', trim($endpoint, '.'));

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return false;
            }

            if (preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
