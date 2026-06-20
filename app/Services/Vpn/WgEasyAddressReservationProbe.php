<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use Illuminate\Support\Facades\Process;
use PDO;
use Throwable;

final readonly class WgEasyAddressReservationProbe
{
    public function __construct(
        private ?string $statePath = null,
    ) {}

    /**
     * @return list<string>
     */
    public function addresses(): array
    {
        return $this->uniqueAddresses([
            ...$this->databaseAddresses(),
            ...$this->fileAddresses('wg0.conf'),
            ...$this->fileAddresses('wg0.json'),
            ...$this->runtimeAddresses(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function databaseAddresses(): array
    {
        $addresses = [];

        foreach ($this->databasePaths() as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }

            try {
                $database = new PDO("sqlite:{$path}", null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);

                $statement = $database->query('select ipv4_address, server_allowed_ips from clients_table');

                if ($statement === false) {
                    continue;
                }

                foreach ($statement->fetchAll() as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    foreach (['ipv4_address', 'server_allowed_ips'] as $column) {
                        $value = $row[$column] ?? null;

                        if (is_string($value)) {
                            array_push($addresses, ...$this->extractAddresses($value));
                        }
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $this->uniqueAddresses($addresses);
    }

    /**
     * @return list<string>
     */
    private function databasePaths(): array
    {
        $paths = [$this->statePath().'/wg-easy.db'];
        $configured = config('services.wg_easy.database_path');

        if (is_string($configured) && trim($configured) !== '') {
            $paths[] = trim($configured);
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private function fileAddresses(string $filename): array
    {
        $path = $this->statePath().'/'.$filename;

        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $this->extractAddresses($contents) : [];
    }

    /**
     * @return list<string>
     */
    private function runtimeAddresses(): array
    {
        $containerId = $this->vpnContainerId();

        if ($containerId === null) {
            return [];
        }

        $result = Process::timeout(10)->run('docker exec '.escapeshellarg($containerId).' wg show wg0 allowed-ips');

        if (! $result->successful()) {
            return [];
        }

        return $this->extractAddresses($result->output());
    }

    private function vpnContainerId(): ?string
    {
        foreach ([
            "docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-vpn'",
            "docker ps -q --filter 'name=wg-easy'",
        ] as $command) {
            $result = Process::timeout(10)->run($command);

            if (! $result->successful()) {
                continue;
            }

            $containerIds = array_values(array_filter(
                array_map(trim(...), preg_split('/\R/', trim($result->output())) ?: []),
            ));

            if ($containerIds !== []) {
                return $containerIds[0];
            }
        }

        return null;
    }

    private function statePath(): string
    {
        if ($this->statePath !== null) {
            return rtrim($this->statePath, '/');
        }

        $configured = config('orbit.paths.config_root');

        if (is_string($configured) && trim($configured) !== '') {
            return rtrim($configured, '/').'/wg-easy';
        }

        $home = getenv('HOME');

        if (! is_string($home) || trim($home) === '') {
            $home = '/root';
        }

        return rtrim($home, '/').'/.config/orbit/wg-easy';
    }

    /**
     * @return list<string>
     */
    private function extractAddresses(string $value): array
    {
        preg_match_all('/(?<!\d)(10\.6\.0\.[0-9]{1,3})(?:\/[0-9]{1,2})?(?!\d)/', $value, $matches);

        return $this->uniqueAddresses(array_values(array_filter(
            $matches[1],
            $this->isManagedWireguardAddress(...),
        )));
    }

    private function isManagedWireguardAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $parts = array_map(intval(...), explode('.', $address));

        return $parts[0] === 10
            && $parts[1] === 6
            && $parts[2] === 0
            && $parts[3] >= 1
            && $parts[3] <= 254;
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private function uniqueAddresses(array $addresses): array
    {
        return array_values(array_unique($addresses));
    }
}
