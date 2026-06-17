<?php

declare(strict_types=1);

namespace App\Services\WireGuard;

use Illuminate\Support\Facades\Process;

class WireGuardGatewayAddressResolver
{
    public function resolve(): ?string
    {
        return $this->resolveFromAddresses($this->localAddresses());
    }

    /**
     * @param  array<int, string>  $addresses
     */
    public function resolveFromAddresses(array $addresses): ?string
    {
        $gatewayIps = [];

        foreach ($addresses as $address) {
            $normalized = $this->normalizeAddress($address);

            if ($normalized === null || ! str_starts_with($normalized, '10.6.')) {
                continue;
            }

            $parts = explode('.', $normalized);

            if (count($parts) !== 4) {
                continue;
            }

            $parts[3] = '2';
            $gatewayIps[] = implode('.', $parts);
        }

        $gatewayIps = array_values(array_unique($gatewayIps));

        if (count($gatewayIps) !== 1) {
            return null;
        }

        return $gatewayIps[0];
    }

    /**
     * @return array<int, string>
     */
    private function localAddresses(): array
    {
        $commands = match (PHP_OS_FAMILY) {
            'Darwin' => ["ifconfig 2>/dev/null | awk '/inet / {print \$2}'"],
            'Linux' => ['hostname -I 2>/dev/null', "ip -o addr show 2>/dev/null | awk '{print \$4}' | cut -d/ -f1"],
            default => [],
        };

        $addresses = [];

        foreach ($commands as $command) {
            $result = Process::timeout(5)->run($command);

            if (! $result->successful()) {
                continue;
            }

            foreach (preg_split('/\s+/', trim($result->output())) ?: [] as $address) {
                $normalized = $this->normalizeAddress($address);

                if ($normalized !== null) {
                    $addresses[] = $normalized;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    private function normalizeAddress(string $address): ?string
    {
        $address = strtolower(trim($address));

        if (str_contains($address, '/')) {
            $address = explode('/', $address, 2)[0];
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return $address;
    }
}
