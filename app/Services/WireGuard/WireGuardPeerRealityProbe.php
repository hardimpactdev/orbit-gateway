<?php

declare(strict_types=1);

namespace App\Services\WireGuard;

use App\Data\WireGuard\WireGuardPeerReality;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class WireGuardPeerRealityProbe
{
    /**
     * @return array<string, WireGuardPeerReality>
     */
    public function peers(string $interface = 'wg-orbit'): array
    {
        $this->assertValidInterface($interface);

        $result = Process::timeout(10)->run("sudo wg show {$interface} allowed-ips");

        if (! $result->successful()) {
            $message = trim($result->errorOutput().' '.$result->output());

            throw new RuntimeException('Failed to read WireGuard peer reality: '.($message !== '' ? $message : 'unknown error'));
        }

        return $this->parseAllowedIps($result->output());
    }

    /**
     * @return array<string, WireGuardPeerReality>
     */
    public function parseAllowedIps(string $output): array
    {
        $peers = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];

            if (count($columns) < 2) {
                continue;
            }

            $publicKey = array_shift($columns);

            if (! is_string($publicKey) || $publicKey === '') {
                continue;
            }

            $allowedIps = array_values(array_filter($columns, fn (string $allowedIp): bool => $allowedIp !== ''));

            $peers[$publicKey] = new WireGuardPeerReality(
                publicKey: $publicKey,
                allowedIps: $allowedIps,
                allowedAddresses: $this->allowedAddresses($allowedIps),
            );
        }

        return $peers;
    }

    private function assertValidInterface(string $interface): void
    {
        if (preg_match('/^[a-zA-Z0-9_.-]+$/', $interface) === 1) {
            return;
        }

        throw new RuntimeException("Invalid WireGuard interface name: {$interface}");
    }

    /**
     * @param  list<string>  $allowedIps
     * @return list<string>
     */
    private function allowedAddresses(array $allowedIps): array
    {
        return array_values(array_filter(array_map(
            fn (string $allowedIp): string => trim(explode('/', trim($allowedIp), 2)[0]),
            $allowedIps,
        )));
    }
}
